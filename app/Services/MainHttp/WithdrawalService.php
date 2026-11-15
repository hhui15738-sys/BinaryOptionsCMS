<?php

namespace App\Services\MainHttp;

use App\Events\ChangeBalance;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\MainHttp\Withdrawal\GetAccountDataRequest;
use App\Http\Requests\MainHttp\Withdrawal\ProcessPayoutRequest;
use App\Http\Requests\MainHttp\Withdrawal\WithdrawalHistoryRequest;
use App\Models\Profile;
use App\Models\Withdrawal;
use App\Models\WithdrawSystem;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\DataTables;

class WithdrawalService
{

    public function getAllWithdrawSysts(Request $request){
        return WithdrawSystem::where('hidden', 0)->orderBy('order', 'asc')->get()->map(function($item){
            $item->id = $item->text;
            return $item;
        });
    }

    public function getAccData(GetAccountDataRequest $request){
        $admin = false;
        if($request->id && Helper::isAdmin()){
            $admin = true;
        }

        $id = $admin ? $request->id : Auth::user()->id;
        User::where('id', $id)->where('left_turnover', '<', 0)->update(['left_turnover' => 0]);
        return $admin ? User::where('id', $id)->first() : Auth::user();
    }

    public function processOfPayout(ProcessPayoutRequest $request){

        $admin = false;
        if($request->id && Helper::isAdmin()){
            if(config('custom.demo')){
                return (['success' => false, 'message' => __('locale.demo_error')]);
            }
            $admin = true;
        }
        $id = $admin ? $request->id : Auth::user()->id;
        $result = DB::transaction(function () use ($id, $request) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($user->balance < $request->amount) {
                return ['error' => __('locale.withdraw_not_enough_money')];
            }
            if ($user->left_turnover > 0) {
                return ['error' => __('locale.withdraw_before')];
            }
            $profile = Profile::where('user_id', $user->id)->first();
            if (!$profile || !$profile->user_verify_at) {
                return ['error' => __('locale.withdraw_verify')];
            }

            $model = new Withdrawal();
            $model->user_id = $user->id;
            $model->status = 0;
            $model->system_id = $request->system_id;
            $model->address = $request->address;
            $model->amount = $request->amount;
            $model->save();

            $user->decrement('balance', $request->amount);
            $user->refresh();
            return ['user' => $user];
        }, 3);

        if (isset($result['error'])) {
            return ['success' => false, 'message' => $result['error']];
        }

        broadcast(new ChangeBalance($result['user']->balance, $result['user']));
        return (['success' => true, 'message' => __('locale.withdraw_success')]);
    }

    public function withdrawalHist(WithdrawalHistoryRequest $request)
    {
        $admin = false;
        if($request->id && Helper::isAdmin()){
            $admin = true;
        }
        $id = $admin ? $request->id : Auth::user()->id;
        $history = Withdrawal::where('user_id', $id)->with(['withdrawSystem'])->get();
        return Datatables::of($history)->make();
    }


}
