<?php

namespace App\Services\MainHttp;

use App\Events\ChangeBalance;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\MainHttp\Deposit\DepositHistoryRequest;
use App\Http\Requests\MainHttp\Deposit\StartDepositRequest;
use App\Models\Deposit;
use App\Models\DepositSystem;
use App\Models\ExchangeRates;
use App\Models\LatestOrder;
use App\Models\Promocode;
use App\Models\PromocodeHistory;
use App\Models\Referral;
use App\User;
use Carbon\Carbon;
use cryptonator\MerchantAPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ixudra\Curl\Facades\Curl;
use Qiwi\Api\BillPayments;
use Yajra\DataTables\DataTables;

class DepositService
{
    public function getAllDepositSystems(Request $request){
        return DepositSystem::where('hidden', 0)->orderBy('order', 'asc')->get()->map(function($item){
            $item->id = $item->text;
            return $item;
        });
    }

    public function qiwiProcess(Request $request){
        $billPayments = new BillPayments(config('deposit.qiwi_secret'));
        $check = $billPayments->checkNotificationSignature(
            $request->header('X-Api-Signature-SHA256'), json_decode($request->getContent(), true), config('deposit.qiwi_secret')
        );
        if($check){
            $orderId = $request['bill']['customFields']['orderId'];
            $this->processDepositServ(
                $orderId,
                $request['bill']['amount']['value'] ?? null,
                $request['bill']['amount']['currency'] ?? null
            );
            return null;
        } else {
            return 'Invalid cipher';
        }
    }

    public function yooMoneyProcess(Request $request){
        $arr = null;
        parse_str($request->getContent(), $arr);
        $sha1 = sha1($arr['notification_type'].'&'.$arr['operation_id'].'&'.$arr['amount'].'&'.$arr['currency'].'&'.$arr['datetime'].'&'.$arr['sender'].'&'.$arr['codepro'].'&'.config('deposit.yoomoney_secret').'&'.$arr['label']);
        if(hash_equals($sha1, (string) $arr['sha1_hash']) and $arr['codepro'] == 'false' and $arr['unaccepted'] == 'false'){
            $this->processDepositServ($arr['label'], $arr['amount'] ?? null, $arr['currency'] ?? 'RUB');
            return true;
        } else {
            return 'Invalid cipher';
        }
    }

    public function cryptonatorProcess(Request $request){
        $cryptonator = new MerchantAPI(config('deposit.cryptonator_id'), config('deposit.cryptonator_secret'));
        $array = null;
        parse_str($request->getContent(), $array);
        if($cryptonator->checkAnswer($array)){
            $this->processDepositServ(
                $array['order_id'],
                $array['invoice_amount'] ?? null,
                $array['invoice_currency'] ?? null
            );
        } else {
            return 'Invalid cipher';
        }
    }

    public function startDeposit(StartDepositRequest $request){

        $promocode_request = intval($request->promocode);
        $amount = number_format($request->amount, 2, '.', '');
        $max = DepositSystem::where('id', $request->system_id)->first()->max;
        if($amount > $max){
            return (['data' => ['success' => false, 'message' => __('locale.deposit_max_amount').' '.$max.'$'], 'status' => 200]);
        }
        $promocode_id = null;
        if($promocode_request) {
            $promocode = Promocode::where('id', $promocode_request)->first();
            if (!$promocode) {
                return (['data' => ['success' => false, 'message' => __('locale.deposit_promo_code')], 'status' => 200]);
            }
            $active_from = Carbon::parse($promocode->active_from);
            $active_to = Carbon::parse($promocode->active_to);
            if(Carbon::now() < $active_from or Carbon::now() > $active_to){
                return (['data' => ['success' => false, 'message' => __('locale.deposit_time_left')], 'status' => 200]);
            }
            $deposits = Deposit::where('user_id', Auth::user()->id)->where('status', 1)->count() + LatestOrder::where('user_id', Auth::user()->id)->count() + PromocodeHistory::where('user_id', Auth::user()->id)->whereHas('promocode', function($query){ $query->where('for_new', 1); })->count();
            if($promocode->for_new and $deposits){
                return (['data' => ['success' => false, 'message' => __('locale.deposit_only_for_new')], 'status' => 200]);
            }
            $promocode_history = PromocodeHistory::where('user_id', Auth::user()->id)->where('promocode_id', $promocode->id)->count();
            if($promocode->attempts && $promocode_history >= $promocode->attempts){
                return (['data' => ['success' => false, 'message' => __('locale.deposit_already_used')], 'status' => 200]);
            }
            if($amount < $promocode->min_amount){
                return (['data' => ['success' => false, 'message' => __('locale.deposit_from_amount').' '.$promocode->min_amount.'$!'], 'status' => 200]);
            }
            $promocode_id = $promocode->id;
        }
        $rub = $amount / ExchangeRates::where('symbol', 'USD')->first()->price;
        $model = new Deposit();
        $model->user_id = Auth::user()->id;
        $model->amount = $amount;
        $model->amount_in_rub = $rub;
        $model->system_id = $request->system_id;
        $model->status = 0;
        $model->promocode_id = $promocode_id;
        $model->save();
        if($request->system_id == 3) { // Cryptonator
            $cryptonator = new MerchantAPI(config('deposit.cryptonator_id'), config('deposit.cryptonator_secret'));
            $url = $cryptonator->startPayment(array(
                'item_name'               => 'Deposit on '.config('custom.domain'),
                'order_id'              => $model->id,
                'item_description'      => 'Deposit on '.config('custom.domain').' for user: '.Auth::user()->email,
                'invoice_amount'          => $amount,
                'invoice_currency'        => 'usd',
                'language'              => 'en',
            ));
            return (['data' => ['success' => true, 'message' => __('locale.deposit_link'), 'link' => $url, 'timeout' => 3000], 'status' => 200]);
        } elseif($request->system_id == 2) { // YooMoney
            $orderId = $model->id;
            $amount = $rub;
            $message = urlencode('Deposit on '.config('custom.domain').' for user: '.Auth::user()->email);
            $success_url = urlencode('https://'.config('custom.domain').'/trading');
            $yoo = Curl::to("https://yoomoney.ru/quickpay/confirm.xml?receiver=".config('deposit.yoomoney_wallet')."&quickpay-form=shop&targets=$message&sum=$amount&label=$orderId&successURL=$success_url&paymentType=AC")
                ->get();
            $link = str_replace('Found. Redirecting to ', '', $yoo);
            return (['data' => ['success' => true, 'message' => __('locale.deposit_link'), 'link' => $link, 'timeout' => 3000], 'status' => 200]);
        } elseif($request->system_id == 1) { // Qiwi
            $billPayments = new BillPayments(config('deposit.qiwi_secret'));
            $billId = $billPayments->generateId();
            Deposit::where('id', $model->id)->update(['billId' => $billId]);
            $lifetime = Carbon::now()->addMinutes(60)->format('Y-m-d\TH:m:s+03:00');
            $fields = [
                'amount' => $rub,
                'currency' => 'RUB',
                'comment' => 'Account replenishment on the site for the user: '.Auth::user()->email,
                'email' => Auth::user()->email,
                'account' => Auth::user()->id,
                'expirationDateTime' => $lifetime,
                'successUrl' => 'https://'.config('custom.domain').'/trading',
                'customFields' => ['orderId' => $model->id],
            ];
            $response = $billPayments->createBill($billId, $fields);
            return (['data' => ['success' => true, 'message' => __('locale.deposit_link'), 'link' => $response['payUrl'], 'timeout' => 3000], 'status' => 200]);
        } elseif($request->system_id == 7) { //Free-Kassa
            $m_shop = config('deposit.free_kassa_id');
            $m_orderid = $model->id;
            $m_curr = 'USD';
            $m_desc = base64_encode(__('locale.deposit_deposit_on_site') . ': ' . config('app.url'));
            $lang = 'ru';
            $m_key = config('deposit.free_kassa_secret');
            $arHash = array(
                $m_shop,
                $m_orderid,
                $amount,
                $m_curr,
                $m_desc
            );
            $arHash[] = $m_key;
            $m_sign = md5(config('deposit.free_kassa_id') . ':' . $amount . ':' . config('deposit.free_kassa_secret') . ':' . $m_orderid);
            $link_for_pay = "https://www.free-kassa.ru/merchant/cash.php?oa=$amount&o=$m_orderid&us_desc=$m_desc&s=$m_sign&m=$m_shop&lang=$lang";
            return (['data' => ['success' => true, 'message' => __('locale.deposit_link'), 'link' => $link_for_pay, 'timeout' => 3000], 'status' => 200]);
        }
        elseif($request->system_id == 6) { //Payeer
            $m_shop = config('deposit.payeer_id');
            $m_orderid = $model->id;
            $m_curr = 'USD';
            $m_desc = base64_encode(__('locale.deposit_deposit_on_site') . ': ' . config('app.url'));
            $lang = 'ru';
            $m_key = config('deposit.payeer_secret');
            $arHash = array(
                $m_shop,
                $m_orderid,
                $amount,
                $m_curr,
                $m_desc
            );
            $arHash[] = $m_key;
            $m_sign = strtoupper(hash('sha256', implode(':', $arHash)));
            $link_for_pay = "https://payeer.com/merchant/?m_shop=$m_shop&m_orderid=$m_orderid&m_amount=$amount&m_curr=$m_curr&m_desc=$m_desc&m_sign=$m_sign&lang=$lang";
            return (['data' => ['success' => true, 'message' => __('locale.deposit_link'), 'link' => $link_for_pay, 'timeout' => 3000], 'status' => 200]);
        }
        elseif($request->system_id == 1){ // Qiwi

        }
        elseif($request->system_id == 7){ // RosKassa

        }
    }

    public function processPayeer(Request $request){
        if (!in_array($request->ip(), array('185.71.65.92', '185.71.65.189', '149.202.17.210'))) return;
        if (isset($request->m_operation_id) && isset($request->m_sign)) {
            $m_key = config('deposit.payeer_secret');
            $arHash = array(
                $request->m_operation_id,
                $request->m_operation_ps,
                $request->m_operation_date,
                $request->m_operation_pay_date,
                $request->m_shop,
                $request->m_orderid,
                $request->m_amount,
                $request->m_curr,
                $request->m_desc,
                $request->m_status,
            );
            if (isset($request->m_params)) {
                $arHash[] = $request->m_params;
            }
            $arHash[] = $m_key;
            $sign_hash = strtoupper(hash('sha256', implode(':', $arHash)));
            if (hash_equals($sign_hash, (string) $request->m_sign) && $request->m_status == 'success') {
                ob_end_clean();
                if($this->processDepositServ($request->m_orderid, $request->m_amount, $request->m_curr)){
                    return $request->m_orderid.'|success';
                } else {
                    return $request->m_orderid.'|error';
                }
            }
            ob_end_clean();
            return $request->m_orderid.'|error';
        }
    }

    public function processFreeKassa(Request $request){
        if (!in_array($request->ip(), array('136.243.38.147', '136.243.38.149', '136.243.38.150', '136.243.38.151', '136.243.38.189', '136.243.38.108'))) return;
        $sign = md5(config('deposit.free_kassa_id').':'.$request->AMOUNT.':'.config('deposit.free_kassa_secret_2').':'.$request->MERCHANT_ORDER_ID);
        if(hash_equals($sign, (string) $request->SIGN)){
            $this->processDepositServ($request->MERCHANT_ORDER_ID, $request->AMOUNT, 'USD');
            return 'YES';
        } else {
            return 'wrong sign';
        }
    }

    public function depositHistory(DepositHistoryRequest $request){
        $admin = false;
        if($request->id && Helper::isAdmin()){
            $admin = true;
        }
        $id = $admin ? $request->id : Auth::user()->id;
        $history = Deposit::where('user_id', $id)->with(['depositSystem'])->get();
        return Datatables::of($history)->make();
    }

    public function processDepositServ($orderId, $paidAmount = null, $currency = null){
        $creditedUser = DB::transaction(function () use ($orderId, $paidAmount, $currency) {
            $deposit = Deposit::whereKey($orderId)->lockForUpdate()->first();
            if (!$deposit || (int) $deposit->status !== 0) {
                return null;
            }

            if ($paidAmount !== null) {
                $expectedAmount = strtoupper((string) $currency) === 'RUB'
                    ? $deposit->amount_in_rub
                    : $deposit->amount;
                if (abs((float) $paidAmount - (float) $expectedAmount) > 0.01) {
                    Log::warning('Rejected deposit callback with mismatched amount', [
                        'deposit_id' => $deposit->id,
                        'paid_amount' => $paidAmount,
                        'expected_amount' => $expectedAmount,
                        'currency' => $currency,
                    ]);
                    return null;
                }
            }

            $user = User::whereKey($deposit->user_id)->lockForUpdate()->firstOrFail();
            $creditAmount = (float) $deposit->amount;
            if ($deposit->promocode_id) {
                $promocode = Promocode::whereKey($deposit->promocode_id)->first();
                if ($promocode) {
                    $bonusAmount = $creditAmount * (float) $promocode->bonus_size / 100;
                    $turnover = $bonusAmount * (float) $promocode->turnover;
                    $creditAmount += $bonusAmount;
                    $user->bonus += $bonusAmount;
                    $user->all_turnover += $turnover;
                    $user->left_turnover += $turnover;
                }
            }

            $user->balance += $creditAmount;
            $user->save();
            $deposit->status = 1;
            $deposit->save();

            if ($user->referer_id) {
                $referer = User::whereKey($user->referer_id)->lockForUpdate()->first();
                if ($referer && $referer->partner_status) {
                    $referer->increment('balance', (float) $deposit->amount * 0.1);
                }
                Referral::where('user_id', $user->referer_id)
                    ->increment('deposit_count', (float) $deposit->amount);
            }

            return $user;
        }, 3);

        if (!$creditedUser) {
            return false;
        }

        broadcast(new ChangeBalance($creditedUser->balance, $creditedUser));
        return true;
    }
}
