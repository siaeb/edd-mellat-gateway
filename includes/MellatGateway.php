<?php

namespace siaeb\edd\gateways\mellat\includes;

class MellatGateway {

	protected $data = [
		'bankKey'       => 'bank_mellat',
		'adminLabel'    => 'بانک ملت',
		'checkoutLabel' => 'بانک ملت',
		'priority'      => 12,
	];

	public function __construct() {
		add_filter( 'edd_payment_gateways', [ $this, 'registerGateway' ], $this->data['priority'] );
		add_filter( 'edd_settings_gateways', [ $this, 'registerSettings' ], $this->data['priority'] );
		add_filter( 'edd_' . $this->data['bankKey'] . '_cc_form', [ $this, 'ccForm' ], $this->data['priority'] );
		add_action( 'edd_gateway_' . $this->data['bankKey'], [ $this, 'processPayment' ], $this->data['priority'] );
		add_action( 'init', [ $this, 'verifyPayment' ] );
	}

	/**
	 * Register gateway
	 *
	 * @param array $gateways
	 *
	 * @return mixed
	 */
	public function registerGateway( $gateways ) {
		$gateways[ $this->data['bankKey'] ] = [
			'admin_label'    => $this->data['adminLabel'],
			'checkout_label' => $this->data['checkoutLabel']
		];
		return $gateways;
	}

	function registerSettings( $settings ) {
		$bank_mellat_settings = [
			[
				'id'   => 'bank_mellat_settings',
				'name' => '<strong>بانک ملت</strong>',
				'desc' => 'پيکربندي درگاه بانک ملت',
				'type' => 'header'
			],
			[
				'id'   => 'bank_mellat_TermID',
				'name' => 'شماره ترمينال',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			],
			[
				'id'   => 'bank_mellat_UserName',
				'name' => 'نام کاربري',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			],
			[
				'id'   => 'bank_mellat_PassWord',
				'name' => 'رمز',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			]
		];

		return array_merge( $settings, $bank_mellat_settings );
	}

	/**
	 * @return mixed|void
	 */
	public function processPayment( $purchaseData ) {
		global $edd_options;
		$bpm_ws = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';
		$i      = 0;
		do {
			$soap = new nusoap_client( $bpm_ws );
			$i ++;
		} while ( $soap->getError() and $i < 3 );
		// Check for Connection error
		if ( $soap->getError() ) {
			edd_set_error( 'pay_00', 'P00:خطایی در اتصال پیش آمد،مجدد تلاش کنید...' );
			edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
		}
		$payment_data = [
			'price'        => $purchaseData['price'],
			'date'         => $purchaseData['date'],
			'user_email'   => $purchaseData['post_data']['edd_email'],
			'purchase_key' => $purchaseData['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchaseData['downloads'],
			'cart_details' => $purchaseData['cart_details'],
			'user_info'    => $purchaseData['user_info'],
			'status'       => 'pending',
		];
		$payment      = edd_insert_payment( $payment_data );
		$PayAddr      = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';
		$terminalId   = isset($edd_options['bank_mellat_TermID']) ? $edd_options['bank_mellat_TermID'] : '';
		$userName     = isset($edd_options['bank_mellat_UserName']) ? $edd_options['bank_mellat_UserName'] : '';
		$userPassword = isset($edd_options['bank_mellat_PassWord']) ? $edd_options['bank_mellat_PassWord'] : '';
		if ( $payment ) {
			$_SESSION['bank_mellat_payment'] = $payment;
			$return                          = add_query_arg( 'order', 'bank_mellat', get_permalink( $edd_options['success_page'] ) );
			$orderId                         = date( 'ym' ) . date( 'His' ) . $payment;
			$amount                          = $purchaseData['price'];
			$localDate                       = date( "Ymd" );
			$localTime                       = date( "His" );
			$additionalData                  = "Purchase key: " . $purchaseData['purchase_key'];
			$payerId                         = 0;
			// pay request
			$parameters = [
				'terminalId'     => sanitize_text_field( $terminalId ),
				'userName'       => sanitize_text_field( $userName ),
				'userPassword'   => sanitize_text_field( $userPassword ),
				'orderId'        => sanitize_text_field( $orderId ),
				'amount'         => $amount,
				'localDate'      => $localDate,
				'localTime'      => $localTime,
				'additionalData' => $additionalData,
				'callBackUrl'    => $return,
				'payerId'        => $payerId
			];
			// Call the SOAP method
			$i = 0;
			do {
				$PayResult = $this->sendRequest( $soap, 'bpPayRequest', $parameters );
				$i ++;
			} while ( $PayResult[0] != "0" and $i < 3 );
			/// end pay request
			if ( $PayResult[0] == "0" ) {
				// Pay Request is Successfull
				echo '
				<form name="MellatPay" method="post" action="' . $PayAddr . '">
				<input type="hidden" name="RefId" value="' . $PayResult[1] . '">
				<script type="text/javascript" language="JavaScript">document.MellatPay.submit();</script></form>
			';
				exit;
			} else {
				edd_update_payment_status( $payment, 'failed' );
				edd_insert_payment_note( $payment, 'P02:' . $this->getErrorMessage( (int) $PayResult[0] ) );
				edd_set_error( 'pay_02', ':P02' . $this->getErrorMessage( (int) $PayResult[0] ) );
				edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
			}
		} else {
			edd_set_error( 'pay_01', 'P01:خطا در ایجاد پرداخت، لطفاً مجدداً تلاش کنید...' );
			edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
		}
	}

	/**
	 * @return mixed|void
	 */
	public function verifyPayment() {
		global $edd_options;
		$terminalId   = isset($edd_options['bank_mellat_TermID']) ? $edd_options['bank_mellat_TermID'] : '';
		$userName     = isset($edd_options['bank_mellat_UserName']) ? $edd_options['bank_mellat_UserName'] : '';
		$userPassword = isset($edd_options['bank_mellat_PassWord']) ? $edd_options['bank_mellat_PassWord'] : '';
		$bpm_ws       = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';
		if ( isset( $_GET['order'] ) and $_GET['order'] == 'bank_mellat' and isset( $_POST['SaleOrderId'] ) and $_SESSION['bank_mellat_payment'] == substr( $_POST['SaleOrderId'], 10 ) and $_POST['ResCode'] == '0' ) {
			$payment         = sanitize_text_field( $_SESSION['bank_mellat_payment'] );
			$RefId           = sanitize_text_field( $_POST['RefId'] );
			$ResCode         = sanitize_text_field( $_POST['ResCode'] );
			$orderId         = sanitize_text_field( $_POST['SaleOrderId'] );
			$SaleOrderId     = sanitize_text_field( $_POST['SaleOrderId'] );
			$SaleReferenceId = sanitize_text_field( $_POST['SaleReferenceId'] );
			$do_inquiry      = false;
			$do_settle       = false;
			$do_reversal     = false;
			$do_publish      = false;
			//Connect to WebService
			$i = 0;
			do {
				$soap = new nusoap_client( $bpm_ws );
				$i ++;
			} while ( $soap->getError() and $i < 5 );//Check for connection errors
			if ( $soap->getError() ) {
				edd_set_error( 'ver_00', 'V00:تراکنش ناموفق بود.<br>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
				edd_update_payment_status( $_SESSION['bank_mellat_payment'], 'failed' );
				edd_insert_payment_note( $_SESSION['bank_mellat_payment'], 'V00:' . '<pre>' . $soap->getError() . '</pre>' );
				edd_send_back_to_checkout( '?payment-mode=bank_mellat' );
			}

			$parameters = array(
				'terminalId'      => $terminalId,
				'userName'        => $userName,
				'userPassword'    => $userPassword,
				'orderId'         => $orderId,
				'saleOrderId'     => $SaleOrderId,
				'saleReferenceId' => $SaleReferenceId
			);
			// verify request
			if ( ! edd_is_test_mode() ) {
				// Call the SOAP method
				$VerResult = $this->getErrorMessage( $soap, 'bpVerifyRequest', $parameters );
				if ( $VerResult[0] == "0" ) {
					// Note: Successful Verify means complete successful sale was done.
					//SETTLE REQUEST
					$do_settle  = true;
					$do_inquiry = false;
				} else {
					//INQUIRY REQUEST
					$do_inquiry = true;
				}
			} else {
				//in test mode
				$do_reversal = true;
				$do_settle   = false;
				$do_publish  = false;
				$do_inquiry  = false;
			}

			// inquiry request
			if ( $do_inquiry ) {
				// Call the SOAP method
				$i = 0;
				do {
					$InqResult = $this->getErrorMessage( $soap, 'bpInquiryRequest', $parameters );
					$i ++;
				} while ( $InqResult[0] != "0" and $i < 4 );

				if ( $InqResult[0] == "0" ) {
					// Note: Successful Inquiry means complete successful sale was done.
					//SETTLE REQUEST
					$do_settle  = true;
					$do_inquiry = false;
				} else {
					//REVERSAL REQUEST
					$do_reversal = true;
					$do_inquiry  = false;
					$do_settle   = false;
				}
			}

			// settle request
			if ( $do_settle ) {
				// Call the SOAP method
				$i = 0;
				do {
					$SettResult = $this->sendRequest( $soap, 'bpSettleRequest', $parameters );
					$i ++;
				} while ( $SettResult[0] != "0" and $i < 5 );
				if ( $SettResult[0] == "0" ) {
					// Note: Successful Settle means that sale is settled.
					$do_publish  = true;
					$do_settle   = false;
					$do_reversal = false;
				} else {
					$do_reversal = true;
					$do_settle   = false;
					$do_publish  = false;
				}
			}

			// reversal request
			if ( $do_reversal ) {
				$i = 0;
				do {//REVERSAL REQUEST
					$RevResult = $this->sendRequest( $soap, 'bpReversalRequest', $parameters );
					$i ++;
				} while ( $RevResult[0] != "0" and $i < 5 );
				// Note: Successful Reversal means that sale is reversed.
				edd_update_payment_status( $payment, 'failed' );
				edd_insert_payment_note( $payment, 'REV:' . $this->getErrorMessage( (int) $RevResult[0] ) );
				edd_set_error( 'rev_' . $RevResult[0], 'R00:تراکنش ناموفق بود.<br>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
				edd_send_back_to_checkout( '?payment-mode=bank_mellat' );
				$do_publish  = false;
				$do_reversal = false;
			}

			if ( $do_publish == true ) {
				// Publish Payment
				$do_publish = false;
				edd_update_payment_status( $payment, 'publish' );
				edd_insert_payment_note( $payment, 'شماره تراکنش:' . $SaleReferenceId );
				echo "<script type='text/javascript'>alert('کد تراکنش خرید بانک : " . $SaleReferenceId . "');</script>";
			}
		} else if ( isset( $_GET['order'] ) and $_GET['order'] == 'bank_mellat' and isset( $_POST['SaleOrderId'] ) and $_SESSION['bank_mellat_payment'] == substr( $_POST['SaleOrderId'], 10 ) and $_POST['ResCode'] != '0' ) {
			edd_update_payment_status( $_SESSION['bank_mellat_payment'], 'failed' );
			edd_insert_payment_note( $_SESSION['bank_mellat_payment'], 'V02:' . $this->getErrorMessage( (int) $_POST['ResCode'] ) );
			edd_set_error( $_POST['ResCode'], $this->getErrorMessage( (int) $_POST['ResCode'] ) );
			edd_send_back_to_checkout( '?payment-mode=bank_mellat' );
		}
	}

	/**
	 * CC Form
	 * @return mixed
	 */
	public function ccForm() {
		return;
	}

	/**
	 * @param $soap
	 * @param $Req
	 * @param $params
	 *
	 * @return array
	 */
	private function sendRequest( $soap, $Req, $params ) {
		$namespace = 'http://interfaces.core.sw.bps.com/';
		$i         = 0;
		do {
			// Call the SOAP method
			$result = $soap->call( $Req, $params, $namespace );
			$i ++;
		} while ( $soap->fault and $i < 3 );

		if ( $soap->fault ) {// Check for a fault
			return array( "-1", "-1" );
		} else if ( $soap->getError() ) {
			return array( "-2", "-1" );
		} else {
			$res = explode( ',', $result );
		}

		return $res;
	}

	private function getErrorMessage( $ecode ) {
		$tmess = "شرح خطا: ";
		switch ( $ecode ) {
			case - 2:
				$tmess .= "شکست در ارتباط با بانک";
				break;
			case - 1:
				$tmess .= "شکست در ارتباط با بانک";
				break;
			case 0:
				$tmess .= "تراکنش با موفقیت انجام شد";
				break;
			case 11:
				$tmess .= "شماره کارت معتبر نیست";
				break;
			case 12:
				$tmess .= "موجودی کافی نیست";
				break;
			case 13:
				$tmess .= "رمز دوم شما صحیح نیست";
				break;
			case 14:
				$tmess .= "دفعات مجاز ورود رمز بیش از حد است";
				break;
			case 15:
				$tmess .= "کارت معتبر نیست";
				break;
			case 16:
				$tmess .= "دفعات برداشت وجه بیش از حد مجاز است";
				break;
			case 17:
				$tmess .= "شما از انجام تراکنش منصرف شده اید";
				break;
			case 18:
				$tmess .= "تاریخ انقضای کارت گذشته است";
				break;
			case 19:
				$tmess .= "مبلغ برداشت وجه بیش از حد مجاز است";
				break;
			case 111:
				$tmess .= "صادر کننده کارت نامعتبر است";
				break;
			case 112:
				$tmess .= "خطای سوییچ صادر کننده کارت";
				break;
			case 113:
				$tmess .= "پاسخی از صادر کننده کارت دریافت نشد";
				break;
			case 114:
				$tmess .= "دارنده کارت مجاز به انجام این تراکنش نمی باشد";
				break;
			case 21:
				$tmess .= "پذیرنده معتبر نیست";
				break;
			case 23:
				$tmess .= "خطای امنیتی رخ داده است";
				break;
			case 24:
				$tmess .= "اطلاعات کاربری پذیرنده معتبر نیست";
				break;
			case 25:
				$tmess .= "مبلغ نامعتبر است";
				break;
			case 31:
				$tmess .= "پاسخ نامعتبر است";
				break;
			case 32:
				$tmess .= "فرمت اطلاعات وارد شده صحیح نیست";
				break;
			case 33:
				$tmess .= "حساب نامعتبر است";
				break;
			case 34:
				$tmess .= "خطای سیستمی";
				break;
			case 35:
				$tmess .= "تاریخ نامعتبر است";
				break;
			case 41:
				$tmess .= "شماره درخواست تکراری است";
				break;
			case 42:
				$tmess .= "تراکنش Sale یافت نشد";
				break;
			case 43:
				$tmess .= "قبلا درخواست Verify داده شده است";
				break;
			case 44:
				$tmess .= "درخواست Verify یافت نشد";
				break;
			case 45:
				$tmess .= "تراکنش Settle شده است";
				break;
			case 46:
				$tmess .= "تراکنش Settle نشده است";
				break;
			case 47:
				$tmess .= "تراکنش Settle یافت نشد";
				break;
			case 48:
				$tmess .= "تراکنش Reverse شده است";
				break;
			case 49:
				$tmess .= "تراکنش Refund یافت نشد";
				break;
			case 412:
				$tmess .= "شناسه قبض نادرست است";
				break;
			case 413:
				$tmess .= "شناسه پرداخت نادرست است";
				break;
			case 414:
				$tmess .= "سازمان صادر کننده قبض معتبر نیست";
				break;
			case 415:
				$tmess .= "زمان جلسه کاری به پایان رسیده است";
				break;
			case 416:
				$tmess .= "خطا در ثبت اطلاعات";
				break;
			case 417:
				$tmess .= "شناسه پرداخت کننده نامعتبر است";
				break;
			case 418:
				$tmess .= "اشکال در تعریف اطلاعات مشتری";
				break;
			case 419:
				$tmess .= "تعداد دفعات ورود اطلاعات بیش از حد مجاز است";
				break;
			case 421:
				$tmess .= "IP معتبر نیست";
				break;
			case 51:
				$tmess .= "تراکنش تکراری است";
				break;
			case 54:
				$tmess .= "تراکنش مرجع موجود نیست";
				break;
			case 55:
				$tmess .= "تراکنش نامعتبر است";
				break;
			case 61:
				$tmess .= "خطا در واریز";
				break;
			default:
				$tmess .= "خطای تعریف نشده";
		}

		return $ecode . ': ' . $tmess;
	}

}
