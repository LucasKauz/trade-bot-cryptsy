<?php
/**
 * API-call related functions
 *
 * @author lucaskauz
 * @license MIT License - https://github.com/
 */
class CryptsyAPI {
    
    const DIRECTION_BUY = 'buy';
    const DIRECTION_SELL = 'sell';
    protected $public_api = 'http://pubapi.cryptsy.com/api.php?';
    
    protected $api_key;
    protected $api_secret;
    protected $noonce;
    protected $RETRY_FLAG = false;
    
    public function __construct($api_key, $api_secret, $base_noonce = false) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        if($base_noonce === false) {
            // Try 1?
            $this->noonce = time();
        } else {
            $this->noonce = $base_noonce;
        }
    }
    
    /**
     * Get the noonce
     * @global type $sql_conx
     * @return type 
     */
    protected function getnoonce() {
        $this->noonce++;
        return array(0.05, $this->noonce);
    }
    
    /**
     * Call the API
     * @staticvar null $ch
     * @param type $method
     * @param type $req
     * @return type
     * @throws Exception 
     */
    public function apiQuery($method, $req = array()) {
        $req['method'] = $method;
        $mt = $this->getnoonce();
        $req['nonce'] = $mt[1];
       
        // generate the POST data string
        $post_data = http_build_query($req, '', '&');
 
        // Generate the keyed hash value to post
        $sign = hash_hmac("sha512", $post_data, $this->api_secret);
 
        // Add to the headers
        $headers = array(
                'Sign: '.$sign,
                'Key: '.$this->api_key,
        );
 
        // Create a CURL Handler for use
        static $ch = null;
        if (is_null($ch)) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
        }
        curl_setopt($ch, CURLOPT_URL, 'https://api.cryptsy.com/api');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
 
        // Send API Request
        $res = curl_exec($ch);        
        
        if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
       $dec = json_decode($res, true);
       if (!$dec) throw new Exception('Invalid data received, please make sure connection is working and requested API exists');
       return $dec;
        
        // Recover from an incorrect noonce
        if(isset($result['error']) === true) {
            if(strpos($result['error'], 'nonce') > -1 && $this->RETRY_FLAG === false) {
                $matches = array();
                $k = preg_match('/:([0-9])+,/', $result['error'], $matches);
                $this->RETRY_FLAG = true;
                trigger_error("Nonce we sent ({$this->noonce}) is invalid, retrying request with server returned nonce: ({$matches[1]})!");
                $this->noonce = $matches[1];
                return $this->apiQuery($method, $req);
            } else {
                throw new CryptsyAPIErrorException('API Error Message: '.$result['error'].". Response: ".print_r($result, true));
            }
        }
        // Cool -> Return
        $this->RETRY_FLAG = false;
        return $result;
    }
    
    /**
     * Retrieve some JSON
     * @param type $URL
     * @return type 
     */
    protected function retrieveJSON($URL) {
        $opts = array('http' =>
            array(
                'method'  => 'GET',
                'timeout' => 10 
            )
        );
        $context  = stream_context_create($opts);
        $feed = file_get_contents($URL, false, $context);
        $json = json_decode($feed, true);
        return $json;
    }
    
    /**
     * Place an order
     * @param type $amount
     * @param type $alt_id
     * @param type $direction
     * @param type $price
     * @return type 
     */
    public function makeOrder($amount, $alt_id, $direction, $price) {
        $data = $this->apiQuery("createorder"
                ,array(
                    'marketid'  => $alt_id, 
                    'ordertype' => $direction,
                    'quantity'  => $amount,
                    'price'     => $price
                )
        );
        return $data; 
    }
    
    /**
     * Check an order that is complete (non-active)
     * @param type $orderID
     * @return type
     * @throws Exception 
     */
    public function checkPastOrder($orderID) {
        $data = $this->apiQuery("allmyorders");
        if($data['success'] == "0") {
            throw new CryptsyAPIErrorException("Error: ".$data['error']);
        } else {
            return($data);
        }
    }

    /**
    * Check open orders
    * @param int $market_id
    * @return array $orders
    */
    public function checkOpenOrders($market_id){
        $params = array("marketid" => $market_id);
        $data = $this->apiQuery("myorders",$params);
        if($data['success'] == "0") {
            throw new CryptsyAPIErrorException("Error: ".$data['error']);
        } else {
            $return_data = $data['return'];
            foreach ($return_data as $dataset) {
                $order_id = $dataset['orderid'];
                $new_return_data[ $order_id ] = $dataset;
            }
            $data['return'] = $new_return_data;
            return($data);
        }
    }

    /**
    * Check specific order
    * @param int $order_id
    * @param int $market_id
    * @return boolean
    */
    public function checkSpecificOrder($order_id, $market_id){
        $opor = $this->checkOpenOrders($market_id);
        if( isset($opor['return'][$order_id]) && !empty($opor['return'][$order_id]) ){
            return true;
        }else{
            return false;
        }
    }

    /**
    * Simulate a order and get the fee amount
    * @param string $ordertype	Order type you are calculating for (Buy/Sell)
    * @param int $quantity	Amount of units you are buying/selling
    * @param float $price	Price per unit you are buying/selling at
    * @return array
    */
    public function simulateOrderFee($ordertype, $quantity, $price){
    	$data = $this->apiQuery("calculatefees"
    	        ,array(
    	            'ordertype' => $ordertype,
    	            'quantity' => $quantity,
    	            'price' => $price
    	        ));
    	if($data['success'] == "0") {
    	    throw new CryptsyAPIErrorException("Error: ".$data['error']);
    	} else {
    	    return($data);
    	}
    }
    
    /**
     * Public API: Get market data
     * @param int $market Market id
     * @return array 
     */
    public function getMarketTicker($market) {
    	$information = 'method=singlemarketdata&marketid='.$market;
    	$mixed_info = $this->retrieveJSON($this->public_api.$information);
    	$market_info = $mixed_info['return']['markets'];
        return $market_info;
    }
}

/**
 * Exceptions
 */
class CryptsyAPIException extends Exception {}
class CryptsyAPIFailureException extends CryptsyAPIException {}
class CryptsyAPIInvalidJSONException extends CryptsyAPIException {}
class CryptsyAPIErrorException extends CryptsyAPIException {}
class CryptsyAPIInvalidParameterException extends CryptsyAPIException {}
?>
