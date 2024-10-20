<?php

// This is CHIP API URL Endpoint as per documented in: https://docs.chip-in.asia
define('AFWP_CHIP_ROOT_URL_PRODUCTION', 'https://api.chip-in.asia/api');
define('AFWP_CHIP_ROOT_URL_STAGING', 'https://staging-api.chip-in.asia/api');

class Chip_For_Affiliatewp_Send_Api {
  private string $api_key;
  private string $api_secret;
  protected string $mode;
  protected int $epoch;
  protected array $header;
  public function __construct($api_key, $api_secret, $mode = 'production') {
    $this->api_key = $api_key;
    $this->api_secret = $api_secret;
    $this->mode = strtoupper($mode);

    $this->generate_epoch();
    
  }

  private function generate_epoch() {
    $this->epoch = $epoch = time();

    $str = $epoch . $this->api_key;
    $hmac = hash_hmac( 'sha512', $str, $this->api_secret );
  
    $this->header =  [
      'Content-Type' => 'application/json' , 
      'Authorization' => "Bearer {$this->api_key}",
      'Checksum' => $hmac,
      'Epoch' => $epoch,
    ];

  }

  private function maybe_refresh_epoch() {
    $current_epoch = time();
    
    if ($current_epoch - $this->epoch > 29) {
      $this->generate_epoch();
    }

  }
  public function get_send_account() {
    return $this->call('GET', '/send/accounts');

  }

  public function get_bank_account( int $id ) {
    return $this->call('GET', "/send/bank_accounts/{$id}");

  }

  public function get_send_instruction( int $id ) {
    return $this->call('GET', "/send/send_instructions/{$id}");

  }

  public function create_bank_account( array $params ) {
    return $this->call('POST', '/send/bank_accounts', $params);

  }

  public function create_send_instruction( array $params ) {
    return $this->call('POST', '/send/send_instructions', $params);

  }

  private function call( $method, $route, $params = [] ) {
    if ( !empty( $params ) ) {
      $params = json_encode( $params );
    }

    $this->maybe_refresh_epoch();

    $response = $this->request(
      $method,
      sprintf( '%s%s', constant( 'AFWP_CHIP_ROOT_URL_' . $this->mode ), $route ),
      $params,
      $this->header
    );
    // log response $response
    
    $result = json_decode( $response, true );
    
    if ( !$result ) {
      // log JSON parsing error/NULL API response
      return null;
    }

    if ( !empty( $result['errors'] ) ) {
      // log API error $result['errors']
      return null;
    }

    return $result;

  }

  private function request($method, $url, $params = [], $headers = []) {
    $wp_request = wp_remote_request( $url, array(
      'method'    => $method,
      'sslverify' => !defined( 'AFWP_CHIP_SSLVERIFY_FALSE' ),
      'headers'   => $headers,
      'body'      => $params,
      'timeout'   => 15,
    ));

    $response = wp_remote_retrieve_body( $wp_request );

    switch ( $code = wp_remote_retrieve_response_code( $wp_request ) ) {
      case 200:
      case 201:
        break;
      default:
        // log error
    }

    if ( is_wp_error( $response ) ) {
      // log error: $response->get_error_message()
    }
    
    return $response;

  }

  public static function get_bank_list() {
    return [
      'ACDBMYK2' => 'AEON Bank (M) Berhad',
      'PHBMMYKL' => 'Affin Bank Berhad',
      'AGOBMYKL' => 'Agrobank',
      'RJHIMYKL' => 'Al-Rajhi',
      'MFBBMYKL' => 'Alliance Bank Malaysia Berhad',
      'ARBKMYKL' => 'Ambank Malaysia Berhad',
      'BIMBMYKL' => 'Bank Islam Malaysia Berhad',
      'BKRMMYKL' => 'Bank Kerjasama Rakyat Malaysia Berhad',
      'BMMBMYKL' => 'Bank Muamalat Malaysia Bhd',
      'BOFAMY2X' => 'Bank of America (M) Berhad',
      'BKCHMYKL' => 'Bank of China (M) Berhad',
      'BOTKMYKX' => 'Bank of Tokyo-Mitsubishi UFJ (M) Berhad',
      'BSNAMYK1' => 'Bank Simpanan Nasional Berhad',
      'BNPAMYKL' => 'BNP Paribas Malaysia Berhad',
      'PCBCMYKL' => 'China Construction Bank (M) Berhad',
      'CIBBMYKL' => 'CIMB Bank Berhad',
      'DEUTMYKL' => 'Deutsche Bank (Malaysia) Berhad',
      'FNXSMYNB' => 'Finexus Cards Sdn. Bhd.',
      'GXSPMYKL' => 'GX Bank Berhad',
      'HLBBMYKL' => 'Hong Leong Bank Berhad',
      'HBMBMYKL' => 'HSBC Bank Malaysia Berhad',
      'ICBKMYKL' => 'Industrial and Commercial Bank of China (M) Berhad',
      'CHASMYKX' => 'JP Morgan Chase Bank Berhad',
      'KFHOMYKL' => 'Kuwait Finance House',
      'MBBEMYKL' => 'Maybank Berhad',
      'AFBQMYKL' => 'MBSB BANK BERHAD',
      'MHCBMYKA' => 'Mizuho Bank (Malaysia) Berhad',
      'OCBCMYKL' => 'OCBC Bank Berhad',
      'PBBEMYKL' => 'Public Bank Berhad',
      'RHBBMYKL' => 'RHB Bank Berhad',
      'SCBLMYKX' => 'Standard Chartered Bank Malaysia Berhad',
      'SMBCMYKL' => 'Sumitomo Mitsui Banking Corporation (M) Berhad',
      'TNGDMYNB' => 'Touch `n Go eWallet',
      'UOVBMYKL' => 'United Overseas Bank Berhad (UOB)',
    ];
  }
}