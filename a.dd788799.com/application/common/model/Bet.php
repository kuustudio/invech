<?php
namespace app\common\model;
use think\Db;
use think\Model;

use app\common\traits\model\UserFlow;

use app\common\model\report\DailyMakerTrait;
//use app\common\model\attach\MoneyRecordTrait;
use app\common\model\report\CommonForFlowTrait;
use app\common\model\report\DailyAndMonthForFlowTrait;
//use app\common\model\report\GlobalUserFromFlowTrait;
//use app\common\model\report\GlobalAgentFromFlowTrait;
use app\common\model\report\GlobalFromFlowTrait;

class Bet extends Base{

    protected $table = 'gygy_bets';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
    protected $autoWriteTimestamp = 'datetime';
    
    use UserFlow;
    use DailyMakerTrait;
    use CommonForFlowTrait,DailyAndMonthForFlowTrait;
    use /*GlobalUserFromFlowTrait,GlobalAgentFromFlowTrait,*/GlobalFromFlowTrait;

    public function money()
    {
        return $this->morphMany('Money',['type','item_id']);
    }

    public function getBetMoneyAttr($value)
    {        
        //快钱玩法和官方玩法的投注金额算法一样
        return $this->data['mode'] * $this->data['beiShu'] * $this->data['actionNum'];
    }

    public function getBaseMoneyAttr($value)
    {
        //每注金额
        return $this->data['mode'] * $this->data['beiShu'];
    }

    public function getWinMoneyAttr($value)
    {        
        if($this->data['lotteryNo']){
            return $this->data['bonus'] - $this->bet_money;
        }else{
            return 0;
        }        
    }

    public function getLotteryTimeAttr($value)
    {        
        if($this->data['kjTime'] >0 ){
            return date('Y-m-d H:i:S',$this->data['kjTime']);
        }else{
            return '';
        }
    }

    //----------------前台------------------
    public function types(){
           return $this->belongsTo('Type','type','id');
    }
    public function lottery(){
           return $this->belongsTo('Type','type','id');
    }

    public function played(){
        return $this->belongsTo('Played','playedId','id');
    }

    public function group(){
        return $this->belongsTo('PlayedGroup','playedGroup','id');
    }

/*
    public function user()
    {
        return $this->belongsTo('Member','uid','uid');
    }
*/


    public static function attachToSelfRequest(&$query,$params=[]){

        $params = request()->param();

        if(isset($params['type']) && is_numeric($params['type'])){
            $query->where('x.type', $params['type']);
        }
        if(isset($params['playedId']) && is_numeric($params['playedId'])){
            $query->where('x.playedId', $params['playedId']);
        }        
    }   

    //----------------后台------------------
    public function scopeSsc($query){return $query->where('type',1);}

    //----api----
    protected $append = ['lottery_title','played_name','group_name',
    'bet_money','win_money','lottery_time','username'];
    protected $visible = ['id','actionData','lotteryNo','fanDian','fanDianAmount','bonus','created_at','bonusProp', ];    
    //protected $hidden = ['user','types','played',];

    public function getLotteryTitleAttr($value,$data){
        return $this->types->title;
    } 
    public function getPlayedNameAttr($value,$data){
        return $this->played->getAttr('name');
    } 
    public function getGroupNameAttr($value,$data){
        return $this->group->getAttr('groupName');
    }   

    //计算中奖数和中奖金额
    public function cal_lottery(LotteryData $lottery_data){

        date_default_timezone_set('PRC') ;

        require_once(ROOT_PATH.'swoole/parse-calc-count.php');
        require_once(ROOT_PATH.'swoole/kqwf_algo.php'); 

        $playeds = Played::getAll();
        $configs = Config::getAll();
        $fanDianMax = $configs['fanDianMax'];

        $played = $playeds[$this->playedId];
        $func = $played->ruleFun;

        $zjAmount = 0;

        $kjData = $lottery_data->getAttr('data');
        
        if($this->weiShu){
            $zjcount = $func($this->actionData,$kjData,$this->weiShu);    
        }else{
            $zjcount = $func($this->actionData,$kjData) ;    
        }       

        if($played->is_kqwf){
            //目前 混合玩法 的 各子玩法 不支持 和局处理;
            if(-1 == $zjcount){//快钱玩法 存在 和局, 退还本金
                $zjAmount = $this->bet_money;                   
            }

            if($zjcount>0){
                $zjAmount = $zjcount * $this->bonusProp * $this->base_money;
            }              
        }else{
            //混合玩法返回各相关玩法的中奖次数;
            if($played->is_mix){

                $mix_ids = _arr($played->mix_ids);
                $mix_pls    = [];//混合玩法的赔率表

                foreach($mix_ids as $mix_id){
                    $mix_played = $playeds[$mix_id];
                    $prop = $mix_played->bonusProp;
                    $base = $mix_played->bonusPropBase;
                    $convertBlMoney = ($prop - $base) / $fanDianMax;
                    $pl = ($prop - $this->fanDian * $convertBlMoney);
                    $mix_pls[] = round($pl,2);                        
                }

                $count_sum = 0;//总中奖次数
                foreach ($mix_pls as $key => $pl) {
                    $count = $zjcount[$key];
                    $zjAmount += $count * $pl * $this->base_money/2;
                    $count_sum += $count;
                }
                
                $zjcount = $count_sum;
            }else{
                //普通玩法
                $zjAmount = $zjcount * $this->bonusProp * $this->base_money/2;
            }  
        }

        $zjcount = (int)$zjcount;
        $zjAmount = round($zjAmount,2);

        return [$zjcount,$zjAmount,];
    }


    public function settlement(LotteryData $lottery_data){

        return transaction(function() use($lottery_data){

            if($this->hasLottery()){
                $this->disableLastLotteryRecord();
            }

            list($zjcount,$zjAmount) = $this->cal_lottery($lottery_data);
            $kjData = $lottery_data->getAttr('data');

            $this->zjCount = $zjcount;
            $this->bonus = $zjAmount;
            $this->lotteryNo = $kjData;

            return transaction(function(){

                $this->save();

                //即时返水,返水处理中已经防止重复返水了;
                if($is_daily_water=1){
                    event('user.bet.back',[$this]);
                }
                /*月结返佣
                if($is_daily_commission=0){
                    event('user.bet.commission',[$this]);
                }
                */

                //if($this->zjCount > 0 || $this->zjCount == -1){
                if($this->bonus > 0){

                    $bl = BetLottery::create([
                        'uid'   =>  $this->uid,
                        'bet_id'    =>  $this->id,
                        'lottery_data'  =>  $this->lotteryNo,
                        'zjCount'   =>  $this->zjCount,
                        'zjAmount'  =>  $this->bonus,
                    ]);

                    if($this->zjCount > 0){
                        $remark = '派奖';
                    }else{
                        $remark = '和局返还本金';
                    }

                    $this->user->incBalance($this->bonus,'lottery',$bl->id,$remark);
                }

                return $this->bonus;
            }); 

        }); 
    }

    public function settlement_bak(LotteryData $lottery_data){

        date_default_timezone_set('PRC') ;

        require_once(ROOT_PATH.'swoole/parse-calc-count.php');
        require_once(ROOT_PATH.'swoole/kqwf_algo.php'); 

        $playeds = Played::getAll();
        $configs = Config::getAll();
        $fanDianMax = $configs['fanDianMax'];

        $played = $playeds[$this->playedId];
        $func = $played->ruleFun;

        $zjAmount = 0;

        $kjData = $lottery_data->getAttr('data');
        
        if($this->weiShu){
            $zjcount = $func($this->actionData,$kjData,$this->weiShu);    
        }else{
            $zjcount = $func($this->actionData,$kjData) ;    
        }       

        if($played->is_kqwf){
            //目前 混合玩法 的 各子玩法 不支持 和局处理;
            if(-1 == $zjcount){//快钱玩法 存在 和局, 退还本金                
                $zjAmount = $this->bet_money;                   
            }

            if($zjcount>0){
                $zjAmount = $zjcount * $this->bonusProp * $this->base_money;
            }              
        }else{
            //混合玩法返回各相关玩法的中奖次数;
            if($played->is_mix){

                $mix_ids = _arr($played->mix_ids);
                $mix_pls    = [];//混合玩法的赔率表

                foreach($mix_ids as $mix_id){
                    $mix_played = $playeds[$mix_id];
                    $prop = $mix_played->bonusProp;
                    $base = $mix_played->bonusPropBase;
                    $convertBlMoney = ($prop - $base) / $fanDianMax;
                    $pl = ($prop - $this->fanDian * $convertBlMoney);
                    $mix_pls[] = round($pl,2);                        
                }

                $count_sum = 0;//总中奖次数
                foreach ($mix_pls as $key => $pl) {
                    $count = $zjcount[$key];
                    $zjAmount += $count*$pl*$this->base_money/2;
                    $count_sum += $count;
                }
                
                $zjcount = $count_sum;
            }else{
                //普通玩法
                $zjAmount = $zjcount * $this->bonusProp * $this->base_money/2;
            }  
        }

        $zjcount = (int)$zjcount;
        $zjAmount = round($zjAmount,2);

        $this->zjCount = $zjcount;
        $this->bonus = $zjAmount;
        $this->lotteryNo = $kjData;

        $money_model = transaction(function(){
                           
            $this->save();

            //即时返水
            if($is_daily_water=1){
                event('user.bet.back',[$this]);
            }
            /*月结返佣
            if($is_daily_commission=0){
                event('user.bet.commission',[$this]);
            }
            */

            //赢/和局
            if($this->zjCount > 0){                   
                $this->user->incBalance($this->bonus,'lottery',$this->id,'派奖');
            }
            if($this->zjCount == -1){                   
                $this->user->incBalance($this->bonus,'lottery',$this->id,'和局返还本金');
            }
        });                                   
        
    }

    /*结算注单 根据 是否派过奖 进行 重新结算;
    //月初计算三个月之前的日统计数据,重结算无法重新统计;
    public function resettlement(LotteryData $lottery_data){
        //zjCount ,bonus,lotteryNo 三个字段记录最近一次的开奖信息
        
        return transaction(function(){

            if($this->bonus > 0){//有对应的有效的派奖记录
                $this->disableLastLotteryRecord();
            }

            return $this->settlement($lottery_data);
        });
    }
    */

    //是否已派奖
    private function hasLottery(){
        return $this->bonus > 0 && !empty($this->lotteryNo); //不用查派奖记录?
    }

    //软删除对应的派奖记录;
    private function disableLastLotteryRecord(){
        return transaction(function(){
            $bl = BetLottery::where('bet_id',$this->id)->order('id desc')->find();
            if($bl){
                $bl->delete();
                $this->user->decBalance($this->bonus,'lottery_debit',$bl->id,'扣除派奖金额',true);
            }
            return;
        });
    }

    public function getBetsByBonusGt($bonus = 0, $limit = 100){
        return $this->alias('a')
            ->join('members b','a.uid = b.uid')
            ->where(['bonus'=>['gt',$bonus]])
            ->order('a.created_at','desc')
            ->limit($limit)->select();
    }
}
