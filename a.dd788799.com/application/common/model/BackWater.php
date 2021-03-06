<?php
namespace app\common\model;
use think\Model;

use app\common\model\report\DailyMakerTrait;
//use app\common\model\attach\MoneyRecordTrait;
use app\common\traits\model\UserFlow;

/*返水有没有必要单独建表? 
没必要?
返水目前只有彩票投注 才有返水; 
bet表记录了注单的返水金额;  
简而言之,返水记录 是一种 特殊的 彩票投注记录;
bet表的返水金额 默认是0 而不是空;
已经返水(返水金额=0) 和 未返水的注单 无法区分? IS NULL 可以区分;
如果不是即时返水,比如月返水, 需要找未返水的注单;

有必要的?
如果后期接真人电子, 有真人类注单, 那些注单 也有返水的话;
返水表 去 多态关联 真人注单表 和 彩票注单表 即可;
*/

class BackWater extends Base{

    protected $table = 'gygy_backwater';
    protected $createTime = 'created_at';
    protected $updateTime = '';
    protected $autoWriteTimestamp = 'datetime';

    use UserFlow;
    use DailyMakerTrait;
    
    //-------------------前台--------------------
    public static function getQlist($request){
        $params =   $request->param();
        $query  =   self::order('id desc');
        if($params['startTime']??''){  
            $query->where('created_at', '>=', $params['startTime']);         
        }
        if($params['endTime']??''){
            $query->where('created_at', '<=', $params['endTime']);
        }
        $query->where('uid',$request->user()->id);
        $options = $query->getOptions();
        $data   =   [];
        $CountAmount    =   $query->sum('amount'); 
        $data['list']   =   $query->options($options)->paginate();
        $data['CountAmount']    =  $CountAmount?$CountAmount:0;
        $PageAmount = 0;
        foreach ($data['list'] as $v){
            $PageAmount = bcadd($PageAmount,$v->amount,2);
        }
        $data['PageAmount']     =  $PageAmount;
        return $data;
    }

    public function user(){
        return $this->belongsTo('Member','uid','uid');
    }

    /* 目前只有投注产生返水
    public function money()
    {
        return $this->morphMany('Money',['type','item_id'],'backwater');
    }

    public function item(){
        return $this->morphTo(['type','item_id'],[
            'recharge'      =>  'app\common\model\Order',
            'withdraw'      =>  'app\common\model\Withdraw',
            'bet'           =>  'app\common\model\Bet',
            'backwater'     =>  'app\common\model\BackWater',
            'commission'    =>  'app\common\model\Commission',
            'bonus'         =>  'app\common\model\Bonus',
        ]);
    }
    */

    //--------------------api--------------------
    protected $append = ['username'];
    protected $hidden = ['uid','type','item_id',];     
}
