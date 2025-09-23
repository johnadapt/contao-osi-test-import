<?php

namespace Bcs\OsiTestImport\Module;

use Contao\BackendModule;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Contao\File;
use Contao\Database;

class CsvFormImport extends BackendModule
{
    protected $strTemplate = 'be_csv_form_import';

    protected function compile(): void
    {
        $this->Template->content = '';

        $tokenManager = System::getContainer()->get('contao.csrf.token_manager');
        $tokenValue = $tokenManager->getDefaultTokenValue();

        if (Input::post('FORM_SUBMIT') === 'tl_csv_form_import') {
            $file = $_FILES['csv_file'] ?? null;
            $delimiterSel = Input::post('delimiter');
            $delimiter = ($delimiterSel === 'semicolon') ? ';' : (($delimiterSel === 'tab') ? "\t" : ',');

            if (!$file || !$file['tmp_name']) {
                Message::addError('Please choose a CSV file.');
            } else {
                $csv = new File($file['tmp_name']);
                $rows = [];

                if (($handle = fopen($csv->path, 'r')) !== false) {
                    while (($data = fgetcsv($handle, 10000, $delimiter)) !== false) {
                        $rows[] = array_map(fn($v) => is_string($v) ? trim($v) : $v, $data);
                    }
                    fclose($handle);
                }

                if (count($rows) < 2) {
                    Message::addError('CSV has no data rows.');
                } else {
                    $headers = array_map('trim', $rows[0]);
                    $dataRows = array_slice($rows, 1);
                    $required = ['form_alias','field_type','field_name','label'];
                    $missing = array_diff($required, $headers);
                    if ($missing) {
                        Message::addError('Missing required columns: ' . implode(', ', $missing));
                    } else {
                        $db = Database::getInstance();
                        $index = array_flip($headers);
                        $allowedTypes = ['checkbox','radio','select','submit'];
                        $createdForms = [];
                        $createdFields = [];
                        $skippedExists = [];
                        $skippedInvalid = [];
                        $formIdCache = [];

                        foreach ($dataRows as $rowIdx => $row) {
                            if (count($row) != count($headers)) {
                                $skippedInvalid[] = ['row' => $rowIdx+2, 'reason' => 'Column count mismatch'];
                                continue;
                            }
                            $r = array_combine($headers, $row);
                            $formAlias = (string)($r['form_alias'] ?? '');
                            $fieldType = strtolower((string)($r['field_type'] ?? ''));
                            $fieldName = (string)($r['field_name'] ?? '');
                            $label     = (string)($r['label'] ?? '');

                            if ($formAlias === '' || $fieldType === '' || $fieldName === '' || $label === '') {
                                $skippedInvalid[] = ['row' => $rowIdx+2, 'reason' => 'Missing required value'];
                                continue;
                            }
                            if (!in_array($fieldType, $allowedTypes, true)) {
                                $skippedInvalid[] = ['row' => $rowIdx+2, 'reason' => 'Unsupported field_type: ' . $fieldType];
                                continue;
                            }
                            $optionsStr = (string)($r['options'] ?? '');
                            if (in_array($fieldType, ['checkbox','radio','select'], true) && $optionsStr === '') {
                                $skippedInvalid[] = ['row' => $rowIdx+2, 'reason' => 'Missing options'];
                                continue;
                            }

                            $formId = $formIdCache[$formAlias] ?? 0;
                            if (!$formId) {
                                $objForm = $db->prepare("SELECT id FROM tl_form WHERE alias=?")->execute($formAlias);
                                if ($objForm->numRows > 0) {
                                    $formId = (int) $objForm->id;
                                } else {
                                    $title = (string)($r['form_title'] ?? $formAlias);
                                    $submitLabel = (string)($r['submit_label'] ?? 'Submit');
                                    $db->prepare("INSERT INTO tl_form (tstamp, title, alias, method, submit) VALUES (?, ?, ?, ?, ?)")
                                        ->execute(time(), $title, $formAlias, 'POST', $submitLabel);
                                    $formId = (int) $db->insertId;
                                    $createdForms[] = ['id' => $formId, 'alias' => $formAlias];
                                }
                                $formIdCache[$formAlias] = $formId;
                            }

                            $objField = $db->prepare("SELECT id FROM tl_form_field WHERE pid=? AND name=?")->execute($formId, $fieldName);
                            if ($objField->numRows > 0) {
                                $skippedExists[] = ['row' => $rowIdx+2, 'name' => $fieldName, 'reason' => 'Field exists'];
                                continue;
                            }

                            $insert = [
                                'tstamp'   => time(),
                                'pid'      => $formId,
                                'type'     => $fieldType,
                                'name'     => $fieldName,
                                'label'    => $label,
                                'class'    => (string)($r['css_class'] ?? ''),
                                'mandatory'=> (int)($r['mandatory'] ?? 0),
                            ];
                            $objMax = $db->prepare("SELECT MAX(sorting) AS maxs FROM tl_form_field WHERE pid=?")->execute($formId);
                            $nextSort = (int)$objMax->maxs + 128;
                            if ($nextSort < 128) $nextSort = 128;
                            $insert['sorting'] = $nextSort;

                            if (in_array($fieldType, ['checkbox','radio','select'], true)) {
                                $insert['optionsType'] = 'manual';
                                $insert['options'] = serialize(self::parseOptions($optionsStr));
                                $multiple = (int)($r['multiple'] ?? 0);
                                $size = (int)($r['size'] ?? 0);
                                if ($fieldType === 'radio') $multiple = 0;
                                $insert['multiple'] = $multiple;
                                if ($size > 0) $insert['size'] = $size;
                            } elseif ($fieldType === 'submit') {
                                $insert['slabel'] = $label;
                            }

                            $db->prepare("INSERT INTO tl_form_field %s")->set($insert)->execute();
                            $createdFields[] = ['row' => $rowIdx+2, 'name' => $fieldName, 'type' => $fieldType];
                        }

                        $this->Template->content .= '<h3>Import Results</h3><ul>';
                        $this->Template->content .= '<li>Forms created: ' . count($createdForms) . '</li>';
                        $this->Template->content .= '<li>Fields created: ' . count($createdFields) . '</li>';
                        $this->Template->content .= '<li>Rows skipped (exists): ' . count($skippedExists) . '</li>';
                        $this->Template->content .= '<li>Rows skipped (invalid): ' . count($skippedInvalid) . '</li>';
                        $this->Template->content .= '</ul>';
                    }
                }
            }
        }

        $action = System::getContainer()->get('request_stack')->getCurrentRequest()->getUri();
        $this->Template->content .= '
        <form action="' . $action . '" method="post" enctype="multipart/form-data">
            <input type="hidden" name="FORM_SUBMIT" value="tl_csv_form_import">
            <input type="hidden" name="REQUEST_TOKEN" value="' . $tokenValue . '">
            <div class="tl_tbox">
                <label for="csv_file">CSV file</label><br>
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
            </div>
            <div class="tl_tbox">
                <label for="delimiter">Delimiter</label><br>
                <select name="delimiter" id="delimiter">
                    <option value="comma">Comma (,)</option>
                    <option value="semicolon">Semicolon (;)</option>
                    <option value="tab">Tab (TAB)</option>
                </select>
            </div>
            <div class="tl_formbody_submit">
                <button type="submit" class="tl_submit">Import CSV</button>
            </div>
        </form>';
    }

    protected static function parseOptions(string $input): array
    {
        $input = trim($input);
        if ($input === '') return [];
        if ($input[0] === '[') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $item) {
                    if (isset($item['value']) && isset($item['label'])) {
                        $out[] = [
                            'value' => (string)$item['value'],
                            'label' => (string)$item['label'],
                            'default' => ''
                        ];
                    }
                }
                return $out;
            }
        }
        $parts = explode('|', $input);
        $out = [];
        foreach ($parts as $p) {
            $bits = explode(':', $p, 2);
            $value = trim($bits[0]);
            $label = trim($bits[1] ?? $bits[0]);
            $out[] = ['value' => $value, 'label' => $label, 'default' => ''];
        }
        return $out;
    }
}
