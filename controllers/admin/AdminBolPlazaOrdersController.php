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

require_once _PS_MODULE_DIR_.'bolplaza/vendor/autoload.php';
require_once _PS_MODULE_DIR_.'bolplaza/bolplaza.php';
require_once _PS_MODULE_DIR_.'bolplaza/classes/BolPlazaPayment.php';
require_once _PS_MODULE_DIR_.'bolplaza/classes/BolPlazaTestPayment.php';
require_once _PS_MODULE_DIR_.'bolplaza/classes/BolPlazaOrderItem.php';

class AdminBolPlazaOrdersController extends AdminController
{
    public function __construct()
    {

        if ($id_order = Tools::getValue('id_order')) {
            Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminOrders').'&vieworder&id_order='.(int)$id_order);
        }

        $this->bootstrap = true;
        $this->table = 'bolplaza_item';
        $this->className = 'BolPlazaOrderItem';

        $this->addRowAction('view');

        $this->identifier = 'id_order';

        $this->_select = 'IF(STRCMP(status,\'shipped\'), 1, 0) as badge_danger, IF (STRCMP(status,\'shipped\'), 0, 1) as badge_success';

        $this->fields_list = array(
            'id_order' => array(
                'title' => $this->l('Order ID'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs'
            ),
            'quantity' => array(
                'title' => $this->l('Quantity'),
                'align' => 'text-left',
                'class' => 'fixed-width-xs'
            ),
            'title' => array(
                'title' => $this->l('Title'),
                'align' => 'text-left',
            ),
            'ean' => array(
                'title' => $this->l('EAN'),
                'align' => 'text-left',
            ),
            'status' => array(
                'title' => $this->l('Status'),
                'align' => 'text-left',
                'badge_danger' => true,
                'badge_success' => true,
                'havingFilter' => true,
                'class' => 'fixed-width-lg'
            ),
        );

        $this->shopLinkType = 'shop';

        parent::__construct();
    }

    public function displayViewLink($token = null, $id = 0, $name = null)
    {
        if ($this->tabAccess['view'] == 1) {
            $tpl = $this->createTemplate('helpers/list/list_action_view.tpl');
            if (!array_key_exists('View', self::$cache_lang)) {
                self::$cache_lang['View'] = $this->l('View', 'Helper');
            }

            $tpl->assign(array(
                'href' => $this->context->link->getAdminLink('AdminOrders').'&vieworder&id_order='.(int)$id,
                'action' => self::$cache_lang['View'],
                'id' => $id
            ));

            return $tpl->fetch();
        } else {
            return;
        }
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $this->page_header_toolbar_btn['sync_orders'] = array(
            'href' => self::$currentIndex.'&token='.$this->token.'&sync_orders=1',
            'desc' => $this->l('Sync orders'),
            'icon' => 'process-icon-download'
        );

        if (Configuration::get('BOL_PLAZA_ORDERS_TESTMODE')) {
            $this->page_header_toolbar_btn['delete_testdata'] = array(
                'href' => self::$currentIndex.'&token='.$this->token.'&delete_testdata=1',
                'desc' => $this->l('Delete test data'),
                'icon' => 'process-icon-eraser'
            );
        }
    }

    public function postProcess()
    {
        /* PrestaShop demo mode */
        if (_PS_MODE_DEMO_) {
            $this->errors[] = Tools::displayError('This functionality has been disabled.');
            return;
        }

        if ((bool)Tools::getValue('sync_orders')) {
            if (!Configuration::get('BOL_PLAZA_ORDERS_ENABLED')) {
                $this->errors[] = Tools::displayError('Bol Plaza API isn\'t enabled for the current store.');
                return;
            }
            $Plaza = BolPlaza::getClient();
            $payment_module = new BolPlazaPayment();
            if ((bool)Configuration::get('BOL_PLAZA_ORDERS_TESTMODE')) {
                $payment_module = new BolPlazaTestPayment();
            }

            foreach ($Plaza->getOpenOrders() as $openOrder) {
                if (!self::getTransactionExists($openOrder->OrderId)) {
                    $cart = $this->parse($openOrder);

                    if (!$cart) {
                        $this->errors[] = $this->l("Couldn't create a cart for order ") .$openOrder->OrderId;
                        continue;
                    }

                    Context::getContext()->cart = $cart;
                    Context::getContext()->currency = new Currency((int)$cart->id_currency);
                    Context::getContext()->customer = new Customer((int)$cart->id_customer);

                    $id_order_state = Configuration::get('BOL_PLAZA_ORDERS_INITIALSTATE'); // TODO CONFIG
                    $amount_paid = self::getBolPaymentTotal($openOrder);
                    $verified = $payment_module->validateOrder(
                        (int)$cart->id,
                        (int)$id_order_state,
                        $amount_paid,
                        $payment_module->displayName,
                        $this->l('Bol.com order'),
                        array(
                            'transaction_id' => $openOrder->OrderId
                        ),
                        null,
                        false,
                        $cart->secure_key
                    );
                    if ($verified) {
                        $this->persistBolItems($payment_module->currentOrder, $openOrder);
                    }

                }
            }
            $this->confirmations[] = $this->l('Bol.com order sync completed.');
        } else if ((bool)Tools::getValue('delete_testdata')) {
            $orders = new PrestaShopCollection('Order');
            $orders->where('module', '=', 'bolplazatest');
            foreach ($orders->getResults() as $order) {
                $customer = $order->getCustomer();
                $addresses = $customer->getAddresses($customer->id_lang);
                foreach ($addresses as $addressArr) {
                    $address = new Address($addressArr['id_address']);
                    $address->delete();
                }
                (new Cart($order->id_cart))->delete();
                $payments = OrderPayment::getByOrderReference($order->reference);
                foreach ($payments as $payment) {
                    $payment->delete();
                }
                $order->deleteAssociations();
                Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'bolplaza_item` WHERE `id_order` = '.(int)pSQL($order->id));
                $order->delete();
                $customer->delete();
            }
        }
    }

    /**
     * Get OrderID for a Transaction ID
     * @param string $transaction id
     * @return array
     */
    public static function getTransactionExists($transaction_id)
    {
        $sql = new DbQuery();
        $sql->select('order_reference');
        $sql->from('order_payment', 'op');
        $sql->where('op.transaction_id = '. pSQL($transaction_id));
        return (bool)Db::getInstance()->executeS($sql);
    }

    public function parse(Picqer\BolPlazaClient\Entities\BolPlazaOpenOrder $openOrder)
    {
        $customer = $this->parseCustomer($openOrder);
        Context::getContext()->customer = $customer;
        $shipping = $this->parseAddress($openOrder->Buyer->ShipmentDetails, $customer, "Shipping");
        $billing  = $this->parseAddress($openOrder->Buyer->BillingDetails, $customer, "Billing");
        $cart     = $this->parseCart($openOrder, $customer, $billing, $shipping);
        return $cart;
    }

    public function parseCustomer(Picqer\BolPlazaClient\Entities\BolPlazaOpenOrder $order)
    {
        $customer = new Customer();
        $customer->lastname = $order->Buyer->BillingDetails->Surname;
        $customer->firstname = $order->Buyer->BillingDetails->FirstName;
        $customer->email = $order->Buyer->BillingDetails->Email;
        $customer->passwd = Tools::passwdGen(8, 'RANDOM');
        $customer->id_default_group = Configuration::get('PS_CUSTOMER_GROUP');
        $customer->add();
        return $customer;
    }

    public function parseAddress(Picqer\BolPlazaClient\Entities\BolPlazaShipmentDetails $details, Customer $customer, $alias)
    {
        $address = new Address();
        $address->id_customer = $customer->id;
        $address->firstname = $details->FirstName;
        $address->lastname = $details->Surname;
        $address->address1 = $details->Streetname;
        $address->address1.= " " . $details->Housenumber;
        if (!empty($details->HousenumberExtended)) {
            $address->address1.= " " . $details->HousenumberExtended;
        }
        $address->address2.= $details->AddressSupplement;
        $address->postcode = $details->ZipCode;
        $address->city = $details->City;
        $address->id_country = Country::getByIso($details->CountryCode);
        $address->alias = $alias;
        $address->add();
        return $address;
    }

    public function parseCart(Picqer\BolPlazaClient\Entities\BolPlazaOpenOrder $openOrder, Customer $customer, Address $billing, Address $shipping)
    {
        $cart = new Cart();
        $cart->id_customer = $customer->id;
        $cart->id_address_delivery = $shipping->id;
        $cart->id_address_invoice = $billing->id;
        $cart->id_shop = (int)Context::getContext()->shop->id;
        $cart->id_shop_group = (int)Context::getContext()->shop->id_shop_group;
        $cart->id_lang = $this->context->language->id;
        $cart->id_currency = Context::getContext()->currency->id;
        $cart->id_carrier = Configuration::get('BOL_PLAZA_ORDERS_CARRIER');
        $cart->recyclable = 0;
        $cart->gift = 0;
        $cart->secure_key = md5(uniqid(rand(), true));
        $cart->add();
        $items = $openOrder->OpenOrderItems;
        $hasProducts = false;
        if (!empty($items)) {
            foreach ($items as $item) {
                $productIds = self::getProductIdByEan($item->EAN);
                if (empty($productIds) || !array_key_exists("id_product", $productIds)) {
                    $this->errors[] = $this->l("Couldn't find product for EAN: ") . $item->EAN;
                    continue;
                }
                $product = new Product($productIds['id_product']);
                if (!Validate::isLoadedObject($product)) {
                    $this->errors[] = $this->l("Couldn't load product for EAN: ") . $item->EAN;
                    continue;
                }
                $hasProducts = true;
                $this->addSpecificPrice($cart, $customer, $product, $productIds['id_product_attribute'], round(self::getTaxExclusive($product, $item->Price), 6));
                $cartResult = $cart->updateQty($item->Quantity, $product->id, $productIds['id_product_attribute']);
                if (!$cartResult) {
                    $this->errors[] = Tools::displayError('Couldn\'t add product to cart. The product cannot be sold because it\'s unavailable or out of stock');
                    return false;
                }
            }
        }

        if (Configuration::get('BOL_PLAZA_ORDERS_FREE_SHIPPING')) {
            $this->addFreeShippingCartRule($cart);
        }

        $cart->update();
        if (!$hasProducts) {
            return false;
        }
        return $cart;
    }

    public function persistBolItems($orderId, Picqer\BolPlazaClient\Entities\BolPlazaOpenOrder $openOrder)
    {
        $items = $openOrder->OpenOrderItems;
        $hasProducts = false;
        if (!empty($items)) {
            foreach ($items as $orderItem) {
                $item = new BolPlazaOrderItem();
                $item->id_shop = (int)Context::getContext()->shop->id;
                $item->id_shop_group = (int)Context::getContext()->shop->id_shop_group;
                $item->id_order = $orderId;
                $item->id_bol_order_item = $orderItem->OrderItemId;
                $item->ean = $orderItem->EAN;
                $item->title = $orderItem->Title;
                $item->quantity = $orderItem->Quantity;
                $item->add();
            }
        }
    }

    private function addSpecificPrice(Cart $cart, Customer $customer, Product $product, $id_product_attribute, $price)
    {
        $specific_price = new SpecificPrice();
        $specific_price->id_cart = (int)$cart->id;
        $specific_price->id_shop = $cart->id_shop;
        $specific_price->id_shop_group = $cart->id_shop_group;
        $specific_price->id_currency = $cart->id_currency;
        $specific_price->id_country = Context::getContext()->country->id;
        $specific_price->id_group = (int)$customer->id_default_group;
        $specific_price->id_customer = (int)$customer->id;
        $specific_price->id_product = $product->id;
        $specific_price->id_product_attribute = $id_product_attribute;
        $specific_price->price = $price;
        $specific_price->from_quantity = 1;
        $specific_price->reduction = 0;
        $specific_price->reduction_type = 'amount';
        $specific_price->from = '0000-00-00 00:00:00';
        $specific_price->to = '0000-00-00 00:00:00';
        $specific_price->add();
    }

    private function addFreeShippingCartRule(Cart $cart)
    {
        $cart_rule = new CartRule();
        $cart_rule->code = BolPlazaPayment::CARTRULE_CODE_PREFIX.(int)$cart->id;
        $cart_rule->name = array(Configuration::get('PS_LANG_DEFAULT') => $this->l('Free Shipping', 'AdminTab', false, false));
        $cart_rule->id_customer = (int)$cart->id_customer;
        $cart_rule->free_shipping = true;
        $cart_rule->quantity = 1;
        $cart_rule->quantity_per_user = 1;
        $cart_rule->minimum_amount_currency = (int)$cart->id_currency;
        $cart_rule->reduction_currency = (int)$cart->id_currency;
        $cart_rule->date_from = date('Y-m-d H:i:s', time());
        $cart_rule->date_to = date('Y-m-d H:i:s', time() + 24 * 36000);
        $cart_rule->active = 1;
        $cart_rule->add();
        $cart->addCartRule((int)$cart_rule->id);
    }

    public static function getTaxExclusive(Product $product, $price)
    {
        $address = Address::initialize();
        $tax_manager = TaxManagerFactory::getManager($address, $product->id_tax_rules_group);
        $tax_calculator = $tax_manager->getTaxCalculator();
        return $tax_calculator->removeTaxes($price);

    }

    public static function getProductIdByEan($ean)
    {
        $id = Product::getIdByEan13($ean);
        if ($id) {
            return array('id_product' => $id, 'id_product_attribute' => 0);
        } else {
            $attributes = self::getAttributeByEan($ean);
            if (count($attributes) == 1) {
                return $attributes[0];
            }
            return $attributes;
        }
    }

    private static function getAttributeByEan($ean)
    {
        if (empty($ean)) {
            return 0;
        }

        if (!Validate::isEan13($ean)) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('pa.id_product, pa.id_product_attribute');
        $query->from('product_attribute', 'pa');
        $query->where('pa.ean13 = \''.pSQL($ean).'\'');
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
    }

    private static function getBolPaymentTotal(Picqer\BolPlazaClient\Entities\BolPlazaOpenOrder $openOrder)
    {
        $items = $openOrder->OpenOrderItems;
        $total = 0;
        if (!empty($items)) {
            foreach ($items as $orderItem) {
                $quantity = $orderItem->Quantity;
                $price = $orderItem->Price;
                $total += $quantity * $price;
            }
        }
        return $total;
    }
}
