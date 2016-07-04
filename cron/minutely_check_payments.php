<?php
include(realpath(dirname(__FILE__))."/../includes/connect.php");
include(realpath(dirname(__FILE__))."/../includes/jsonRPCClient.php");

$script_start_time = microtime(true);

if ($argv) $_REQUEST['key'] = $argv[1];

if ($_REQUEST['key'] != "" && $_REQUEST['key'] == $GLOBALS['cron_key_string']) {
	$q = "SELECT * FROM currency_invoices i JOIN invoice_addresses a ON i.invoice_address_id=a.invoice_address_id WHERE i.status != 'confirmed' AND i.status != 'settled' AND (i.status='unconfirmed' OR i.expire_time >= ".time().");";
	$r = run_query($q);

	while ($invoice = mysql_fetch_array($r)) {
		$confirm_it = false;

		$api_unconfirmed_balance = json_decode(file_get_contents('https://blockchain.info/q/addressbalance/'.$invoice['pub_key'].'?confirmations=0'))/pow(10,8);
		$api_confirmed_balance = json_decode(file_get_contents('https://blockchain.info/q/addressbalance/'.$invoice['pub_key'].'?confirmations=1'))/pow(10,8);

		$qq = "UPDATE currency_invoices SET confirmed_amount_paid='".$api_confirmed_balance."', unconfirmed_amount_paid='".$api_unconfirmed_balance."'";
		if ($api_unconfirmed_balance >= $invoice['pay_amount'] || $api_confirmed_balance >= $invoice['pay_amount']) {
			$qq .= ", status='confirmed'";
			$confirm_it = true;
		}
		else if ($api_unconfirmed_balance > 0) {
			$qq .= ", status='unconfirmed'";
		}
		$qq .= " WHERE invoice_id='".$invoice['invoice_id']."';";
		$rr = run_query($qq);

		if ($confirm_it) {
			// Todo: make sure amounts match what's expected to make sure no one tampered with the db since the invoice was generated
			$invoice_exchange_rate = historical_currency_conversion_rate($invoice['settle_price_id'], $invoice['pay_price_id']);
			$expected_pay_amount = round(pow(10,8)*$settle_amount/$invoice_exchange_rate)/pow(10,8);

			$qq = "SELECT * FROM games WHERE game_id='".$invoice['game_id']."';";
			$rr = run_query($qq);
			$game = mysql_fetch_array($rr);

			if ($game['giveaway_status'] == "public_pay" || $game['giveaway_status'] == "invite_pay") {
				ensure_user_in_game($invoice['user_id'], $game['game_id']);

				$qq = "SELECT * FROM user_games WHERE user_id='".$invoice['user_id']."' AND game_id='".$game['game_id']."';";
				$rr = run_query($qq);

				if (mysql_numrows($r) > 0) {
					$user_game = mysql_fetch_array($rr);

					if ($user_game['paid_invoice_id'] > 0) {
						$qq = "UPDATE currency_invoices SET status='pending_refund' WHERE invoice_id='".$invoice['invoice_id']."';";
						$rr = run_query($qq);
					}
					else {
						$qq = "UPDATE user_games SET paid_invoice_id='".$invoice['invoice_id']."', payment_required=0 WHERE user_game_id='".$user_game['user_game_id']."';";
						$rr = run_query($qq);

						$giveaway = new_game_giveaway($game, $invoice['user_id']);
					}
				}
			}
		}
	}

	echo "Script ran for ".round(microtime(true)-$script_start_time, 2)." seconds.<br/>\n";
}
else echo "Error: permission denied.";
?>