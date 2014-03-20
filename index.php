<?php

/*
 # Cryptsy bot for any altcoin
 # Lucas Kauz
 # http://lucaskauz.com.br
 # Based on work by github.com/ipsBruno/trade-bot-btce
 
*/

error_reporting(E_ALL);
set_time_limit(0);


# Load library
include 'cryptsy.php';

header('Content-Type: text/html; charset=utf-8');

# API Keys
$key = '';
$secret = '';

 
$Cryptsy = new CryptsyAPI($key, $secret);

# Basic informations
$ordens = 0;
$alt = 0;
$trading = 0;

# Value to add when the price don't match
$addPrice 	= 0.0005;

# Ticker
$alt_ticker = 'AUR';

# Market id
$alt_id = 160;

# Server data default
$servidor = array();

# Minimum BTC for trading
$trading_value = 0.0025;

# Exchange fee ( percentage value )
$fee = 0.3;

# Maximum buy order time ( in minutes )
$tempo = 20;


# Check if there is a open sell order
if(isset($_GET["c"]))
{
	if(@$Cryptsy->checkSpecificOrder($_GET["c"],$alt_id)) 
	{
		$oid = $_GET["c"];	
		
		die("<p>Aguardando fechamento da ordem de venda...</p> <script>setTimeout(function(){location.href='".$_SERVER['PHP_SELF']."?c=".$oid."';},5000);</script>");
	}
	die("<p>Ordem fechada com sucesso, preparando para criar nova ordem pra compra...</p> <script>setTimeout(function(){location.href='".$_SERVER['PHP_SELF']."';},5000);</script>");
}



# Get basic info
try {

    $informacoes = $Cryptsy->apiQuery('getinfo');

    $ordens = $informacoes["return"]["openordercount"];

    $alt = $informacoes["return"]["balances_available"][$alt_ticker];

    $trading = $informacoes["return"]["balances_available"]["BTC"];


} catch(CryptsyException $e) {
    RefazerTrade($e->getMessage());
}

?>

<!DOCTYPE html>
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7"> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8"> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js"> <!--<![endif]-->
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title>Trade Bot - Cryptsy</title>
    <style>
	body{
		font-family: Helvetica, Arial;
	}

	h1,
	h2,
	h3{
		clear: both;
		margin: 0px;
		padding: 17px 0 7px 0;
		font-size: 18px;
		color: #333;
	}

	h1{
		font-size: 22px;
	}

	h2{
		font-size: 18px;
	}

	h3{
		font-size: 15;
	}

	p{
		border: solid 1px #ccc;
		padding: 6px 7px;
		float: left;
		margin: 4px 0;
		font-size: 13px;
	}

	p > span,
	p > b{
		float: left;
	}

	p > span{
		width: 176px;
		display: block;
		border-right: solid 1px #ccc;
		margin-right: 10px;
		text-align: center;
	}

	#info_block{
		width: 450px;
	}
    </style>
</head>
<body>

<?php

# Checking if you have the minimum BTC quantity

if($trading < $trading_value) {
   RefazerTrade("Você não tem valor de ".$alt_ticker." ou BTCs suficiente para trade!");
}

# Get the buy and sell altcoin price
$servidor['markets_tickers'] = $Cryptsy->getMarketTicker($alt_id);
$servidor['alt_ticker'] = $servidor['markets_tickers'][$alt_ticker];
$max  = $servidor['alt_ticker']["sellorders"][0]['price'];
$min  = $servidor['alt_ticker']["buyorders"][0]['price'];


if($max < $min)  RefazerTrade("Valor nao encontrado");

recalcular:
# Format the number
$max 		= number_format($max,8);
$min 		= number_format($min,8);
$lucro 		= number_format($max - $min,8);
$prejuizo 	= number_format( ($max * $fee / 100) + ($min * $fee / 100),8);

//echo "Rodada:<br> Minimo > $min <br> Maximo > $max <br> Prejuizo > $prejuizo <br> Lucro > $lucro <br> ";
;

# If the profit is lower than the loss we need to add the quantity specified above
if($lucro < $prejuizo)  {
	$max += $addPrice;
	$min -= $addPrice;
	goto recalcular;
}

?>


<div id="info_block">
	<h3>[ALGORÍTIMO PARA PROCURA DE LUCRO]</h3>
	<p><span>Estimativa de lucro</span>    <b><?php echo $lucro; ?> BTC</b>
	<p><span>Estimativa de prejuizo</span> <b><?php echo $prejuizo; ?> BTC</b>
	<h2>Valores</h2>
	<p><span>Valor de compra</span> <b><?php echo $min; ?> BTC</b></p>
	<p><span>Valor de venda</span>  <b><?php echo $max; ?> BTC</b></p>
</div>


<?php

# Altcoin quantity
$comprarbtc_nofee =  GetAltcoinAmmount($trading, $min) ;
$comprarbtc = GetAltcoinFreeFee($comprarbtc_nofee,$fee);

# Open buy order
$oid = @ComprarAltcoins( $min, $comprarbtc,$alt_id );

# Verify if the buy order was ok
if($oid == 0) RefazerTrade("<h3 class='erro'>[ERRO] Ocorreu um erro ao criar as ordens!</h3>") ;

# Fix the flush
force_flush();


# For each 5 seconds we will try to open a buy order while we still have time ( specified before )

$tentativas = 0;

while(@$Cryptsy->checkSpecificOrder($oid,$alt_id)) {
	$tentativas ++;
	
	Sleep(5);
	
	if($tentativas > $tempo*60/5) {
	
		$Cryptsy->apiQuery("cancelorder", array("orderid" => $oid));
		RefazerTrade("<h2>Não consegui fazer trade desta vez! :( ORDEM CANCELADA. Tentando novamente... </h2>");
	
	}
}


# Completed buy order notice
echo "<h2>[COMPRA] Ordem Fechada.</h2>";

# Altcoin quantity
$comprarbtc_nofee =  GetAltcoinAmmount($trading, $min) ;
$comprarbtc = GetAltcoinFreeFee($comprarbtc_nofee,$fee);
	
# Generate sell order
$oid = @VenderBitcoins($max, $comprarbtc, $alt_id) ;

# Verify if the sell order was ok
if($oid == 0) RefazerTrade("<h3 class='erro'>[ERRO] Ocorreu um erro ao criar as ordens!</h3>") ;

# Finish, checking if the sell order is completed
die("<script>setTimeout(function(){location.href='".$_SERVER['PHP_SELF']."?c=".$oid."';},5000);</script>");


 
##########################
#
# Funções para o sistema
#
##########################

function GetAltcoinFreeFee($bitcoin, $fee) {
	return $bitcoin -= ($bitcoin * $fee / 100);
}

function GetBitcoinValue($dolares, $bitcoin) {
	return $dolares / $bitcoin;
}

function GetAltcoinAmmount($dolares, $value) {
	return $dolares / $value;  
}


function force_flush() {
    echo "\n\n<!-- Deal with browser-related buffering by sending some incompressible strings -->\n\n";
    for ( $i = 0; $i < 5; $i++ )
        echo "<!-- 

abcdefghijklmnopqrstuvwxyz1234567890aabbccddeeffgghhiijjkkllmmnnooppqqrrssttuuvvwwxxyyzz11223344556677889900abacbcbdcdcededfefegfgfhghgihihjijikjkjlklkmlmlnmnmononpopo

qpqprqrqsrsrtstsubcbcdcdedefefgfabcadefbghicjkldmnoepqrfstugvwxhyz1i234j567k890laabmbccnddeoeffpgghqhiirjjksklltmmnunoovppqwqrrxsstytuuzvvw0wxx1yyz2z113223434455666777

889890091abc2def3ghi4jkl5mno6pqr7stu8vwx9yz11aab2bcc3dd4ee5ff6gg7hh8ii9j0jk1kl2lmm3nnoo4p5pq6qrr7ss8tt9uuvv0wwx1x2yyzz13aba4cbcb5dcdc6dedfef8egf9gfh0ghg1ihi2hji3jik4jk

j5lkl6kml7mln8mnm9ono -->\n\n";
    while ( ob_get_level() )
        ob_end_flush();
    @ob_flush();
    @flush();
} # force_flush()



function VenderBitcoins($valorbtc, $quantosbtc, $alt_id) {
	global $Cryptsy, $alt_ticker;
	
	try {
		echo "<h2>[VENDA] Ordem Criada: $quantosbtc ".$alt_ticker.". Por: ".$valorbtc." BTC ( cada )</h2>";
		$r = $Cryptsy->makeOrder( $quantosbtc , $alt_id, 'Sell', $valorbtc);
				//var_dump($r);	
		return $r["orderid"];		
		
	} catch(CryptsyAPIInvalidParameterException $e) {
		echo $e->getMessage();
		return false;
		
	} catch(CryptsyAPIException $e) {
		echo $e->getMessage();
		return false;
	}
	

}


function ComprarAltcoins($valorbtc, $quantosbtc, $alt_id) {
	global $Cryptsy, $alt_ticker;
	
	try {
		echo "<h2>[COMPRA] Ordem Criada: {$quantosbtc} {$alt_ticker}. Cotação: {$valorbtc} BTCs ( cada )</h2>";	

		$r = $Cryptsy->makeOrder($quantosbtc , $alt_id, 'Buy', $valorbtc);
			//var_dump($r);
		return $r["orderid"];
		
	} catch(CryptsyAPIInvalidParameterException $e) {
		echo $e->getMessage().'a';
		return false;
		
	} catch(CryptsyAPIException $e) {
		echo $e->getMessage().'b';
		return false;
	}
	
}


function ChecarOrdem($id,$alt_id) {
	global $Cryptsy;
	
	try {
    
    		$params = array('marketid' => $alt_id);
    
    		$r = ($Cryptsy->apiQuery('marketorders', $params));
    
    		if(count($r["return"][$id])) return true;
	} 
	catch(BTCeAPIException $e) {
    		return false;
	}	
	return false;		
}

function RefazerTrade($motivo) {
   print "<script>setTimeout(function(){location.reload();},5000);</script>";
   die($motivo);
}
 
 
?>
</body>