<?php

namespace Bcs\OsiTestImport\Module;

use Contao\BackendModule;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Contao\File;
use Contao\Database;
use Contao\FilesModel;
use Contao\Folder;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CsvFormImport extends BackendModule
{
    protected $strTemplate = 'be_csv_form_import';

    protected function compile(): void
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $session = $request->getSession();

        /** @var CsrfTokenManagerInterface $tokenManager */
        $tokenManager = System::getContainer()->get('contao.csrf.token_manager');
        $tokenValue = $tokenManager->getDefaultTokenValue();
        $this->Template->tokenValue = $tokenValue;

        //
        // Ensure example CSV exists in /files/test_csv_importer and is marked public
        //
        $folderPath = 'files/test_csv_importer';
        $exampleCsvPath = $folderPath . '/example_forms.csv';
        $projectDir = System::getContainer()->getParameter('kernel.project_dir');
        $absoluteExample = $projectDir . '/' . $exampleCsvPath;

        // Create folder if missing (and mark public)
        $folder = new Folder($folderPath);
        $model  = FilesModel::findByPath($folderPath);
        if ($model !== null && !$model->public) {
            $model->public = 1;
            $model->save();
        }

        // Create example_forms.csv if it does not exist
        if (!file_exists($absoluteExample)) {
            $exampleContent = "form_title,embed_code,percentage,question,option_1,option_2,option_3,option_4,option_5,option_6,option_7,option_8,option_9,option_10,correct_option\n"
                . "AAA-Safety Quiz,\"<div>Example embed code or iframe</div>\",90,When handling chemicals,Use gloves,Wear goggles,Ignore safety,Wash hands,,,,,,,\"2,3\"\n";
            file_put_contents($absoluteExample, $exampleContent);
            FilesModel::syncFiles();
        }

        //
        // Steps
        //
        $step = Input::post('IMPORT_STEP') ?: Input::get('step');

        // === Result page ===
        if ($step === 'result' && $session->has('bcs_csv_form_import_results')) {
            $this->strTemplate = 'be_csv_form_import_result';
            $this->Template    = new \Contao\BackendTemplate($this->strTemplate);

            $results = $session->get('bcs_csv_form_import_results');
            $this->Template->summary   = $results['summary'];
            $this->Template->details   = $results['details'];
            $this->Template->formsList = $results['formsList'];
            $this->Template->failures  = $results['failures'];
            $this->Template->backUrl   = $this->addToUrl('', true, ['step']);
            $session->remove('bcs_csv_form_import_results');
            return;
        }

        // === Preview page ===
        if ($step === 'preview' && $session->has('bcs_csv_form_import_preview')) {
            $this->strTemplate          = 'be_csv_form_import_preview';
            $this->Template             = new \Contao\BackendTemplate($this->strTemplate);
            $this->Template->tokenValue = $tokenValue;
            // $this->Template->forms      = $session->get('bcs_csv_form_import_preview');
            
$forms = $session->get('bcs_csv_form_import_preview');

// Process each question so preview shows multiple correct answers
foreach ($forms as &$form) {
    foreach ($form['questions'] as &$q) {

        $rawCorrect = trim((string)$q['correct_option']);
        $correctList = array_map('trim', explode(',', $rawCorrect));

        // Attach parsed correct list to preview
        $q['_correctList'] = $correctList;
    }
}
unset($form, $q);

$this->Template->forms = $forms;
            

            if (Input::post('FORM_SUBMIT') === 'tl_csv_form_import_confirm') {
                $results = $this->doImport($this->Template->forms);
                $session->remove('bcs_csv_form_import_preview');
                $session->set('bcs_csv_form_import_results', $results);

                $uri = $request->getUri();
                $sep = (strpos($uri, '?') === false) ? '?' : '&';
                header('Location: ' . $uri . $sep . 'step=result');
                exit;
            }
            return;
        }

        // === Upload page ===
        if (Input::post('FORM_SUBMIT') === 'tl_csv_form_import') {
            $file      = $_FILES['csv_file'] ?? null;
            $delimiter = ','; // always comma

            if (!$file || !$file['tmp_name']) {
                Message::addError('Please choose a CSV file.');
                return;
            }

            $csv  = new File($file['tmp_name']);
            $rows = [];
            if (($handle = fopen($csv->path, 'r')) !== false) {
                while (($data = fgetcsv($handle, 10000, $delimiter)) !== false) {
                    $rows[] = $data;
                }
                fclose($handle);
            }

            if (count($rows) < 2) {
                Message::addError('CSV has no data rows.');
                return;
            }

            // Normalize headers to lowercase (so "Question" works)
            $rawHeaders = $rows[0];
            $headers    = array_map(function ($h) {
                return strtolower(trim($h));
            }, $rawHeaders);

            // Only these headers are required now
            $requiredHeaders = ['form_title', 'question', 'option_1', 'correct_option'];
            foreach ($requiredHeaders as $req) {
                if (!in_array($req, $headers, true)) {
                    Message::addError('Missing required column header: ' . $req);
                    return;
                }
            }

            $dataRows = array_slice($rows, 1);
            $forms    = [];

            foreach ($dataRows as $rowIndex => $row) {
                $issues = [];

                // Row length issues
                if (count($row) !== count($headers)) {
                    $issues[] = 'Column count mismatch (expected ' . count($headers) . ', got ' . count($row) . ')';
                }

                // Build rowData by headers (pad with empty strings if short)
                $rowData = [];
                foreach ($headers as $i => $col) {
                    $rowData[$col] = $row[$i] ?? '';
                }

                // Map "question" -> "label" (DB uses label)
                if (isset($rowData['question'])) {
                    $rowData['label'] = $rowData['question'];
                }

                // Validate required fields per-row
                foreach ($requiredHeaders as $req) {
                    if ($req === 'correct_option') {
                        $raw = trim((string) $rowData['correct_option']);

                        if ($raw === '') {
                            $issues[] = 'Missing required field: correct_option';
                            continue;
                        }

                        // Support: "2" OR "2,4" OR "2, 4, 5"
                        $parts = array_map('trim', explode(',', $raw));
                        foreach ($parts as $part) {
                            if ($part === '' || !ctype_digit($part)) {
                                $issues[] = 'Invalid correct_option entry: "' . $part . '" (must be a number between 1 and 10)';
                                continue 2; // break out of requiredHeaders loop for this row
                            }
                            $c = (int) $part;
                            if ($c < 1 || $c > 10) {
                                $issues[] = 'Invalid correct_option: ' . $part . ' (must be between 1 and 10)';
                                continue 2;
                            }
                        }
                        continue;
                    }

                    if ($rowData[$req] === '') {
                        $issues[] = 'Missing required field: ' . $req;
                    }
                }

                // Extra validation: each correct option must point to a non-empty option
                $rawCorrect = trim((string) $rowData['correct_option']);
                if ($rawCorrect !== '') {
                    $parts = array_map('trim', explode(',', $rawCorrect));
                    foreach ($parts as $part) {
                        if (!ctype_digit($part)) {
                            // already handled above as an issue; skip here
                            continue;
                        }
                        $c = (int) $part;
                        $optKey = 'option_' . $c;
                        if (empty($rowData[$optKey])) {
                            $issues[] = 'Correct option "' . $part . '" points to empty ' . $optKey;
                        }
                    }
                }

                $rowData['_issues'] = $issues;

                $formTitle = trim((string)($rowData['form_title'] ?? ''));
                if (!isset($forms[$formTitle])) {
                    $forms[$formTitle] = [
                        'title'      => $formTitle,
                        'embed_code' => $rowData['embed_code'] ?? '',
                        'percentage' => $rowData['percentage'] ?? '',
                        'questions'  => [],
                    ];
                }

                $forms[$formTitle]['questions'][] = $rowData;
            }

            $session->set('bcs_csv_form_import_preview', $forms);
            $uri = $request->getUri();
            $sep = (strpos($uri, '?') === false) ? '?' : '&';
            header('Location: ' . $uri . $sep . 'step=preview');
            exit;
        }
    }

    protected function doImport(array $forms): array
    {
        $db            = Database::getInstance();
        $createdForms  = 0;
        $createdFields = 0;
        $formMap       = [];
        $log           = [];
        $failures      = [];

        // Pass 1: Forms
        $log[] = 'Starting Pass 1: Creating forms…';

        foreach ($forms as $form) {
            $slugService = System::getContainer()->get('contao.slug');
            $slug        = $slugService->generate($form['title'], []);
            $percentage  = ($form['percentage'] !== '' ? (int) $form['percentage'] : 90);
            $tempFormId  = 'tmp_' . time();

            try {
                $db->prepare("INSERT INTO tl_form
                    (tstamp, title, alias, jumpTo, method, attributes, formID)
                    VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute(
                       time(),
                       $form['title'],
                       $slug,
                       '51',
                       'POST',
                       serialize([]),
                       $tempFormId
                   );
            } catch (\Exception $e) {
                $msg = 'Form insert SQL error for ' . $form['title'] . ': ' . $e->getMessage();
                $log[] = $msg; $failures[] = $msg;
                continue;
            }

            $formId = $db->insertId;
            if (!$formId) {
                $stmt = $db->prepare("SELECT id FROM tl_form WHERE alias=? ORDER BY id DESC")
                           ->limit(1)
                           ->execute($slug);
                if ($stmt->numRows) {
                    $formId = $stmt->id;
                }
            }

            if (!$formId) {
                $msg = 'Form insert failed for: ' . $form['title'];
                $log[] = $msg; $failures[] = $msg;
                continue;
            }

            // regenerate slug with ID appended
            $newSlug = $slug . '-' . $formId;

            try {
                $db->prepare("UPDATE tl_form
                    SET alias=?, formID=?, formType=?, scoringType=?, embed_code=?, percentage=?, publish=?
                    WHERE id=?")
                   ->execute(
                        $newSlug,
                       'cert_test_' . $formId,
                       'test',
                       'percentage_correct',
                       $form['embed_code'],
                       $percentage,
                       1,
                       $formId
                   );
            } catch (\Exception $e) {
                $msg = 'Form update SQL error for ' . $form['title'] . ': ' . $e->getMessage();
                $log[] = $msg; $failures[] = $msg;
                continue;
            }

            $formMap[$form['title']] = $formId;
            $createdForms++;
            $log[] = 'Form created: ' . $form['title'] . ' (ID ' . $formId . ')';
        }

        $log[] = 'Pass 1 complete: ' . $createdForms . ' forms created.';
        $log[] = 'Starting Pass 2: Creating form fields…';

        // Pass 2: Fields
        foreach ($forms as $form) {
            if (empty($formMap[$form['title']])) {
                continue;
            }

            $formId         = $formMap[$form['title']];
            $sorting        = 64;
            $formFieldCount = 0;

            foreach ($form['questions'] as $question) {
                // Skip invalid rows (show why in results)
                if (!empty($question['_issues'])) {
                    $failures[] = 'Skipped invalid row in form ' . $form['title'] . ': ' . implode('; ', $question['_issues']);
                    continue;
                }

                // Parse correct_option as comma-separated list: "2" or "2,4,5"
                $correctRaw  = trim((string)($question['correct_option'] ?? ''));
                $correctList = [];
                if ($correctRaw !== '') {
                    $correctList = array_map('trim', explode(',', $correctRaw));
                }

                // Build options array (value/label + correct mark)
                $options = [];
                for ($i = 1; $i <= 10; $i++) {
                    $key = 'option_' . $i;
                    $opt = trim($question[$key] ?? '');
                    if ($opt !== '') {
                        $optArr = ['value' => (string) $i, 'label' => $opt];
                        if (in_array((string)$i, $correctList, true)) {
                            $optArr['correct'] = '1';
                        }
                        $options[] = $optArr;
                    }
                }

                // Decide field type based on number of correct answers
                $correctCount = count($correctList);
                $fieldType = ($correctCount > 1)
                    ? 'multiple_choice_question_multiple_answers'
                    : 'multiple_choice_question';

                try {
                    $db->prepare("INSERT INTO tl_form_field
                        (tstamp, pid, sorting, type, mandatory, label, name, options)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                       ->execute(
                           time(),
                           $formId,
                           $sorting,
                           $fieldType,
                           1,
                           $question['label'],   // mapped from "question"
                           'question_'.$formId."_".$sorting,
                           serialize($options)
                       );

                    $fieldId = $db->insertId;
                    if ($fieldId) {
                        $db->prepare("UPDATE tl_form_field SET name=? WHERE id=?")
                           ->execute('question_date_' . $fieldId, $fieldId);
                    }
                } catch (\Exception $e) {
                    $msg = 'Field insert error in form ' . $form['title'] . ': ' . $e->getMessage();
                    $log[] = $msg; $failures[] = $msg;
                    continue;
                }

                $createdFields++;
                $formFieldCount++;
                $sorting += 64;
            }

            // Submit button (always last)
            $sorting += 64;
            try {
                $db->prepare("INSERT INTO tl_form_field
                    (tstamp, pid, sorting, type, mandatory, class, slabel)
                    VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute(
                       time(),
                       $formId,
                       $sorting,
                       'submit',
                       0,
                       'button btn',
                       'Submit Answers'
                   );
                $formFieldCount++;
            } catch (\Exception $e) {
                $msg = 'Submit button insert error in form ' . $form['title'] . ': ' . $e->getMessage();
                $log[] = $msg; $failures[] = $msg;
            }

            $log[] = 'Fields created for ' . $form['title'] . ': ' . $formFieldCount;
        }

        $log[] = 'Pass 2 complete: ' . $createdFields . ' total question fields created.';

        $formList = [];
        foreach ($formMap as $title => $id) {
            $formList[] = ['title' => $title, 'id' => $id];
        }

        return [
            'summary'   => '<ul><li>Forms created: ' . $createdForms . '</li><li>Questions created: ' . $createdFields . '</li></ul>',
            'details'   => '<div class="import-log">' . implode('<br>', $log) . '</div>',
            'failures'  => $failures,
            'formsList' => $formList,
        ];
    }
}
