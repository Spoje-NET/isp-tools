<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Reminder package
 *
 * https://github.com/SpojeNET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet;

/**
 * Description of Deblocker.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class DeBlocker extends \Ease\Sand
{
    public function __construct()
    {
        $this->setObjectName('Deblocker');
        $this->customer = new \AbraFlexi\Bricks\Customer();
    }

    public function getBlocked(): void
    {
        $adresses = $deblocker->customer->adresar->getColumnsFromAbraFlexi(
            ['kod', 'stitky', 'nazev'],
            ['stitky' => 'ODPOJENO', 'limit' => 0],
            'kod',
        );
        $lmsIDs = [];
        $deblocker->addStatusMessage(\count($adresses).' '._('customers with label DISCONNECTED'));
    }
}
