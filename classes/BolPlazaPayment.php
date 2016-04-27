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
 *  @copyright 2013-2016 Wienk IT
 *  @license   LICENSE.txt
 */

class BolPlazaPayment extends PaymentModule
{
    public $active = 1;
    public $name = 'bolplaza';

    const CARTRULE_CODE_PREFIX = 'BOLPLAZA_';

    public function __construct()
    {
        $this->displayName = $this->l('Bol Plaza order');
    }
}
