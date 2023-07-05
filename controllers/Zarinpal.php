<?php
defined('BASEPATH') or exit('No direct script access allowed');

class zarinpal extends App_Controller
{
	public function zarinpal_decrypt($merchant, $string)
	{
		global $site;

		$output 		= false;
		$encrypt_method = "AES-256-CBC";
		$secret_key 	= "payment-{$merchant}-encrypt";
		$secret_iv 		= md5($secret_key);
		$key 			= hash('sha256', $secret_key);
		$iv 			= substr(hash('sha256', $secret_iv), 0, 16);

		$output 		= openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);

		return $output;
	}

	public function callback()
    {
		$inv 			= $this->input->get('inv');
		$hash 			= $this->input->get('hash');
		$authority 		= $this->input->get('Authority');
		$status 		= $this->input->get('Status');

        check_invoice_restrictions($inv, $hash);

		$this->db->where('token', $authority);
        $this->db->where('id', $inv);
        $db_token = $this->db->get(db_prefix().'invoices')->row()->token;

        if ($db_token != $authority)
		{
            set_alert('danger', 'توکن پرداخت معتبر نیست');
            redirect(site_url("invoice/{$inv}/{$hash}"));
        } else {
			$merchant_id = $this->zarinpal_gateway->decryptSetting('merchant_id');
			$amount 	= $this->zarinpal_decrypt($merchant_id, $this->session->userdata('zarinpal_payment_key'));

			if (isset($status) && strtoupper($status) == 'OK')
			{
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_URL, 'https://api.zarinpal.com/pg/v4/payment/verify.json');
				curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
				curl_setopt($curl, CURLOPT_POSTFIELDS, "merchant_id={$merchant_id}&amount={$amount}&authority={$authority}");
				curl_setopt($curl, CURLOPT_TIMEOUT, 10);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				$curl_exec = curl_exec($curl);
				curl_close($curl);

				$result = json_decode($curl_exec, true);

				if (isset($result['data']['code']) && $result['data']['code'] == 100)
				{
					$success = $this->zarinpal_gateway->addPayment(
					[
						'amount'        => $amount,
						'invoiceid'     => $inv,
						'transactionid' => $authority,
					]);

					set_alert('success', 'پرداخت شما با موفقیت انجام و ثبت شد');

					redirect(site_url("invoice/{$inv}/{$hash}"));
				} else {
					$result_code 	= (isset($result['errors']['code']) && $result['errors']['code'] != "") 		? $result['errors']['code'] 	: "Error connecting to web service";
					$result_message = (isset($result['errors']['message']) && $result['errors']['message'] != "") 	? $result['errors']['message'] 	: "Error connecting to web service";

					set_alert('danger', "خطا در اتصال به درگاه پرداخت<br /><div style='text-align:left; direction:ltr;'>{$result_message}</div>");
					log_activity("zarinpal Payment Error [ Error CODE: {$result_code} Message: {$result_message} ]");
					redirect(site_url("invoice/{$inv}/{$hash}"));
				}
			} else {
				set_alert('danger', 'تراکنش توسط کاربر لغو شد');
				log_activity('zarinpal Payment Error [Error CODE: 0 Message: Transaction Canceled By User]');
				redirect(site_url("invoice/{$inv}/{$hash}"));
			}
		}
    }
}