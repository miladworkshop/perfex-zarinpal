<?php
defined('BASEPATH') or exit('No direct script access allowed');

class zarinpal_gateway extends App_gateway
{
    public function __construct()
    {
        /**
         * Call App_gateway __construct function
         */
        parent::__construct();
        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('zarinpal');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('زرین پال');

        /**
         * Add gateway settings
        */
        $this->setSettings([
            [
                'name'      	=> 'merchant_id',
                'encrypted' 	=> true,
                'label'     	=> 'کد درگاه ( Merchant )',
			],
			[
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'شناسه پرداخت {invoice_number}',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'IRT,IRR',
			],
		]);
    }

	public function zarinpal_encrypt($merchant, $string)
	{
		global $site;

		$output 		= false;
		$encrypt_method = "AES-256-CBC";
		$secret_key 	= "payment-{$merchant}-encrypt";
		$secret_iv 		= md5($secret_key);
		$key 			= hash('sha256', $secret_key);
		$iv 			= substr(hash('sha256', $secret_iv), 0, 16);

		$output 		= openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
		$output 		= base64_encode($output);

		return $output;
	}

    /**
     * REQUIRED FUNCTION
     * @param  array $data
     * @return mixed
     */
    public function process_payment($data)
    {
		$merchant_id 	= $this->decryptSetting('merchant_id');
		$amount 		= preg_replace('~\.0+$~','', $data['amount']);
		$currency 		= (isset($data['invoice']->currency_name) && strtoupper($data['invoice']->currency_name) == "IRT") ? "IRT" : "IRR";
		$order_id 		= format_invoice_number($data['invoice']->id);
		$description 	= "پرداخت شناسه {$order_id}";
		$callback_url 	= urlencode(site_url("zarinpal/callback?hash={$data['invoice']->hash}&inv={$data['invoiceid']}"));

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'https://api.zarinpal.com/pg/v4/payment/request.json');
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
		curl_setopt($curl, CURLOPT_POSTFIELDS, "merchant_id={$merchant_id}&amount={$amount}&currency={$currency}&description={$description}&callback_url={$callback_url}");
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$curl_exec = curl_exec($curl);
		curl_close($curl);

		$result = json_decode($curl_exec, true);

		if (isset($result['data']['code']) && $result['data']['code'] == 100)
		{
			// Save Session
			$this->ci->session->set_userdata([
				'zarinpal_payment_key' => $this->zarinpal_encrypt($merchant_id, $amount),
			]);

			// Add the token to database
			$this->ci->db->where('id', $data['invoiceid']);
			$this->ci->db->update(db_prefix().'invoices', [
				'token' => $result['data']['authority'],
			]);

			redirect("https://www.zarinpal.com/pg/StartPay/{$result['data']['authority']}");
		} else {
			$result_code 	= (isset($result['errors']['code']) && $result['errors']['code'] != "") 		? $result['errors']['code'] 	: "Error connecting to web service";
			$result_message = (isset($result['errors']['message']) && $result['errors']['message'] != "") 	? $result['errors']['message'] 	: "Error connecting to web service";

			set_alert('danger', "خطا در اتصال به درگاه پرداخت<br /><div style='text-align:left; direction:ltr;'>{$result_message}</div>");
			log_activity("zarinpal Payment Error [ Error CODE: {$result_code} Message: {$result_message} ]");
			redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
		}
    }
}