<?php
/**
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author    Mark Wienk
 *  @copyright 2013-2017 Wienk IT
 *  @license   LICENSE.txt
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_5()
{
    return Db::getInstance()->execute(
        'ALTER TABLE `'._DB_PREFIX_.'bolplaza_ownoffers` ADD `title` VARCHAR(255) AFTER `description`;'
    ) && Db::getInstance()->execute(
        'ALTER TABLE `'._DB_PREFIX_.'bolplaza_ownoffers` ADD `fulfillment` VARCHAR(10) AFTER `description`;'
    ) && Db::getInstance()->execute(
        'ALTER TABLE `'._DB_PREFIX_.'bolplaza_ownoffers` DROP COLUMN `reference`;'
    );
}
