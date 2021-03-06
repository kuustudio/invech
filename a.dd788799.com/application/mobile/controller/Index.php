<?php
// +----------------------------------------------------------------------
// | OneThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.onethink.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: 麦当苗儿 <zuojiazi@vip.qq.com> <http://www.zjzit.cn>
// +----------------------------------------------------------------------

namespace app\mobile\Controller;
use app\mobile\Base;
use think\Validate;

/**
 * 前台首页控制器
 * 主要获取首页聚合数据
 */
class Index extends Base
{
    /**
     * 系统首页
     * @return mixed|string
     */
    public function index()
    {
        echo 1 ;die;
		//$lists = model('score_goods')->where(array('isDelete'=>0, 'enable'=>1))->order('id')->select() ;
		$lists = [];
		$this->assign('lists',$lists);//列表
		return $this->fetch('index');
    }

    public function login(){
        if(request()->isGet()){
            if($this->isLogin()){
                $this->redirect('index/index');
            }
            return $this->fetch("login");
        }else{
    
            //if(!captcha_check($_POST['dlyzm'])){}

            $username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $data = array(
                    'login_name' =>  $username,
                    'login_pwd'  =>  $password,
            );
            
            $rules = ['login_name'=> 'require|length:4,12|alphaNum',
                      'login_pwd' => 'require|length:6,32'
            ];
            $msg = [
                'login_name.require'  =>  '用户名必须填写!',
                'login_name.length'   =>  '用户名长度必须在6~12之间!',
                'login_name.alphaNum' =>  '用户名必须是数字和字母的组合!',
                'login_pwd.require'  =>  '密码必须填写!',
                'login_pwd.length'   =>  '密码长度必须在6~32位之间!',             
            ];
            $validate = new Validate($rules,$msg);
            if(! $validate->check($data))
            {
                $error = $validate->getError();
    
                return ['status'=>'-1','msg'=>$error,];
            }

            $rememberme = @$_POST['rember'] == '1';
    
            if($this->doLogin($data['login_name'],$data['login_pwd'],$rememberme)){
                return ['status'=>0,'msg'=>'登录成功!'];
            }else{
                return ['status'=>-1,'msg'=>'用户名或密码错误!'];
            }
        }
    }

    public function register(){
        return $this->fetch();
    }

}
