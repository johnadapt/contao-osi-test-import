<?php

namespace Bcs\OsiTestImport\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Bcs\OsiTestImport\BcsOsiTestImportBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create(BcsOsiTestImportBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class])
        ];
    }
}
