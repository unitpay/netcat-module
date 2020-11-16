<?

class nc_payment_system_unitpay extends nc_payment_system {

    const ERROR_SIGNATURE = 'Invalid signature';
    const ERROR_SUM = 'Invalid sum';
    const ERROR_CURRENCY = 'Invalid currency';

    protected $automatic = TRUE;

    // принимаемые валюты
    protected $accepted_currencies = array('RUB', 'RUR', 'USD', 'EUR');
    protected $currency_map = array('RUR' => 'RUB');

    // параметры сайта в платежной системе
    protected $settings = array(
        'DOMAIN' => null,
        'PUBLIC KEY' => null,
        'SECRET KEY' => null,
    );

    public function execute_payment_request(nc_payment_invoice $invoice) {
        ob_end_clean();
        header("Location: " . $this->get_pay_request_url($invoice));
        exit;
    }

    /**
     * @param nc_payment_invoice $invoice
     * @return string
     */
    protected function get_pay_request_url(nc_payment_invoice $invoice) {


        $secret_key = $this->get_setting('SECRET KEY');
        $sum = $invoice->get_amount('%0.2F');
        $account = $invoice->get_id();
        $desc = 'Заказ №' . $invoice->get_id();
        $currency = $this->get_currency_code($invoice->get_currency());
        $email = $invoice->get('customer_email');
        $phone = preg_replace('/\D/', '', $invoice->get('customer_phone'));
        $signature = hash('sha256', join('{up}', array(
            $account,
            $currency,
            $desc,
            $sum,
            $secret_key
        )));;

        // подготовка параметров для запроса
        $query_values = array(
            'sum' => $sum,
            'account' => $account,
            'desc' => $desc,
            'currency' => $currency,
            'signature' => $signature
        );

        if ($email){
            $query_values['customerEmail'] = $email;
        }

        if ($phone){
            $query_values['customerPhone'] = $phone;
        }

        if ($email || $phone){
            $query_values['cashItems'] = $this->get_cash_items($invoice->get_items(), $currency);
        }

        $query = $this->make_query_string($query_values);

        $domain = $this->get_setting('DOMAIN');

        return "https://$domain/pay/" . $this->get_setting('PUBLIC KEY') . "?" . $query;
    }

    /**
     * @param nc_payment_invoice_item_collection $items
     * @param $currencyCode
     * @return array|string
     */
    protected function get_cash_items($items, $currencyCode)
    {
        $order_products = [];
        /**
         * @var nc_payment_invoice_item $item
         */
        foreach ($items as $item) {
            switch ($item->get('vat_rate')){
                case '20':
                    $vat = 'vat20';
                    break;
                case '10':
                    $vat = 'vat10';
                    break;
                case '0':
                    $vat = 'vat0';
                    break;
                default:
                    $vat = 'none';
            }

            $order_products[] = [
                'name'     => $item->get('name'),
                'count'    => $item->get('qty'),
                'price'    => round($item->get('item_price'), 2),
                'currency' => $currencyCode,
                'type'     => ($item->get('name') == 'Доставка')? 'service' : 'commodity',
                'nds'      => $vat
            ];
        }

        return base64_encode(json_encode($order_products));
    }

    /**
     * @param array $params
     * @return string
     */
    protected function make_query_string($params) {
        return http_build_query($params, '', '&');
    }

    /**
     *
     */
    public function validate_payment_request_parameters() {

    }

    /**
     * @param nc_payment_invoice $invoice
     */
    public function on_response(nc_payment_invoice $invoice = null) {

        $data = $_GET;
        $method = $data['method'];

        switch ($method) {
            case 'check':
                $result = $this->check( $invoice );
                break;
            case 'pay':
                $result = $this->pay( $invoice );
                break;
            case 'error':
                $result = $this->error( $invoice );
                break;
            default:
                $result = array('error' =>
                    array('message' => 'неверный метод')
                );
                break;
        }
        $this->returnJson($result);
    }

    /**
     * @param nc_payment_invoice $invoice
     */
    public function validate_payment_callback_response(nc_payment_invoice $invoice = null) {

        $data = $_GET;
        $method = '';
        $params = $data;
        if ((isset($data['params'])) && (isset($data['method'])) && (isset($data['params']['signature']))){
            $params = $data['params'];
            $method = $data['method'];
            $signature = $params['signature'];
            if (empty($signature)){
                $status_sign = false;
            }else{
                $status_sign = $this->verifySignature($params, $method);
            }
        }else{
            $status_sign = false;
        }

//        $status_sign = true;

        if (!$status_sign){
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
            $this->add_error(self::ERROR_SIGNATURE);
            $this->returnJson($result);
        }else{

            if (!$invoice){
                $result = array('error' =>
                    array('message' => 'неверный номер заказа')
                );
                //add_error не нужна сама добавляется в другом месте
                $this->returnJson($result);
            }else{

                $total = $invoice->get_amount('%0.2F');
                $currency = $this->get_currency_code($invoice->get_currency());
                if (!isset($params['orderSum']) || ((float)$total != (float)$params['orderSum'])) {
                    $result = array('error' =>
                        array('message' => 'не совпадает сумма заказа')
                    );
                    $this->add_error(self::ERROR_SUM);

                    $invoice->set('status', nc_payment_invoice::STATUS_CALLBACK_ERROR);
                    $invoice->save();

                    $this->returnJson($result);
                }elseif (!isset($params['orderCurrency']) || ($currency != $params['orderCurrency'])) {
                    $result = array('error' =>
                        array('message' => 'не совпадает валюта заказа')
                    );
                    $this->add_error(self::ERROR_CURRENCY);

                    $invoice->set('status', nc_payment_invoice::STATUS_CALLBACK_ERROR);
                    $invoice->save();

                    $this->returnJson($result);
                }

            }

        }

    }

    public function load_invoice_on_callback() {
        $params = $this->get_response_value('params');
        $account = null;
        if ($params) {
            $account = $params['account'];
        }
        return $this->load_invoice($account);
    }

    function check( nc_payment_invoice  $invoice )
    {
        $invoice->set('status', nc_payment_invoice::STATUS_WAITING);
        $invoice->save();

        $result = array('result' =>
            array('message' => 'Запрос успешно обработан')
        );
        return $result;
    }

    function pay( nc_payment_invoice  $invoice )
    {
        $invoice->set('status', nc_payment_invoice::STATUS_SUCCESS);
        $invoice->save();

        $this->on_payment_success($invoice);
        $result = array('result' =>
            array('message' => 'Запрос успешно обработан')
        );

        return $result;
    }
    function error( nc_payment_invoice  $invoice  )
    {

        $invoice->set('status', nc_payment_invoice::STATUS_CALLBACK_ERROR);
        $invoice->save();

        $this->on_payment_failure($invoice);

        $result = array('result' =>
            array('message' => 'Запрос успешно обработан')
        );
        return $result;
    }
    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);
        return hash('sha256', join('{up}', $params));
    }
    function verifySignature($params, $method)
    {
        $secret = $this->get_setting('SECRET KEY');
        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }

    function returnJson( $arr )
    {
        header('Content-Type: application/json');
        $result = json_encode($arr);
        echo $result;
    }

}
