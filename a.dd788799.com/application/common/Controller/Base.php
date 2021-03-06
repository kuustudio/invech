<?php
namespace app\common\Controller;
use think\Controller;
use think\Loader;

class Base extends Controller{
	protected function _initialize(){
        parent::_initialize();

        $this->initConst();
	}

    private function initConst()
    {                
        $request = request();
        $module = $request->module();       
        //$controller = lcfirst($request->controller());        
        $controller = Loader::parseName($request->controller());
        $action = $request->action();

        defined('MODULE') or define('MODULE'     , $module);
        defined('CONTROLLER') or define('CONTROLLER' , $controller);
        defined('ACTION') or define('ACTION'     , $action);
    }
}