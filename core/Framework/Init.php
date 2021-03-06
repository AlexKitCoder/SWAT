<?php
namespace Core\Framework;
use \Core\Framework\Context;
class Init
{
    private static $instance;
    private function __construct() {}
    private function __clone() {}
    
    public static function getInstance()
    {
        if(!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param  array $route
     * @return mixed
     */
    public function go(array $route)
    {
        if($this->envCheck()) {
            $this->start($route);
        } else {
            echo 'SWAT need php version >= 7.1 :(';
            return false;
        }
    }

    public function envCheck()
    {
        $version = substr(phpversion(),0,3);
        if($version < '7.1') {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @param array $route
     * @return mixed
     */
    public function start(array $route)
    {
        $this->setConfByEnv();//上下文保存项目配置
        $http = new \Swoole\Http\Server(Context::getConf('host'), Context::getConf('port'));
        $http->set([
            'daemonize'  => Context::getConf('daemonize'),
            'worker_num' => Context::getConf('worker_num'),
        ]);
        $http->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($route){
            $requestUri = $request->server['request_uri'];

            if($requestUri !== '/favicon.ico' && $this->needNginx($requestUri)) {
                //var_dump($requestUri);
                $requestAction = $this->parseRoute($route, $requestUri);
                if($this->fileExists($requestAction['controllerPath'])) {
                    $controllerInstance = new $requestAction['namespacePath'];
                    $action = $requestAction['action'];
                    Context::set($request, $response);//保存请求上下文
                    //注意当前保存请求上下文信息是为了控制器等实例可以使用，response应答后应当销毁
                    try{
                        Context::getResponse()->end($controllerInstance->$action());//执行控制器方法并输出到浏览器
                    } catch(\Exception $e) {
                        Context::getResponse()->end($e->getMessage()."\r\nerrno: ".$e->getCode());
                        return;
                    }
                    Context::clearContextHeader();//销毁上下文请求头部分
                } else {
                    echo 'file: '.$requestAction['controllerPath'].' not exsit :(';
                    return false;
                }
                
            }
            
        });

        $http->on('WorkerStart', function(\Swoole\Http\Server $http, int $workerId) {
            echo '进程:'.$workerId.' start'."\r\n";
            try{
                if(!is_null(Context::getConf('mysql'))) {
                    \Core\Db\Mysql::getInstance()->init()->keepConns();
                } 
            } catch(\Exception $e) {
                echo $e->getMessage();
                return;
            } catch(\Error $e) {
                echo $e->getMessage();
                return;
            }
            
        });

        $http->start();
    }


    /**
     * @param  array $route
     * @param  string $requestUri
     * @return array ['controllerPath'=>'xxx...'namespacePath'=>'\App\Controllers\Index','controller'=>'Index','action'=>'index']
     */
    private function parseRoute(array $route, string $requestUri)
    {
        if(is_array($route)) {
            $actArr = [];
            $actArr['namespacePath'] = '';
            foreach($route as $k=>$v) {
                if($requestUri == $k) {
                    $actionArr = $route[$k];
                    $implodeAction = explode('@',$actionArr);
                    $actArr['action'] = $implodeAction[1];
                    $dirFile = array_values(array_filter(explode('/',$implodeAction[0])));
                    $count = count($dirFile);
                    $actArr['controller'] = $dirFile[$count-1];
                    $baseNamespacePath = '\\App\\Controllers\\';
                    $basePath = APP_ROOT.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Controllers';
                    if($count >= 2) {
                        for($i=0;$i<$count-1;$i++) {
                            $basePath .= DIRECTORY_SEPARATOR.$dirFile[$i];
                            $baseNamespacePath .= ucfirst($dirFile[$i]).'\\';
                        }
                        $actArr['namespacePath'] .= $baseNamespacePath.$actArr['controller'];
                        $basePath .= DIRECTORY_SEPARATOR.$dirFile[$count-1].'.php';
                    } else {
                        $basePath .= DIRECTORY_SEPARATOR.$dirFile[0].'.php';
                        $actArr['namespacePath'] .= $actArr['controller'];
                    }
                    $actArr['controllerPath'] = $basePath;
                    break;
                }
            }
            return $actArr;
        } else {
            echo 'routes.php must return array!';
            return false;
        }
    }

    /**
     * 检测文件是否存在
     * @param string $filePath
     * @return bool
     */
    private function fileExists(string $filePath)
    {
        if(file_exists($filePath)) {
            return true;
        }
        return false;
    }

    /**
     * 根据配置的环境设置环境配置
     */
    private function setConfByEnv()
    {
        $basePath = APP_ROOT.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR;
        $env = require($basePath.'conf.php');
        $currentEnv = $env['env'] ?? null;
        $envConf = [];
        switch($currentEnv) {
            case 'dev':
                $envConf = require($basePath.'dev.php');
                break;
            case 'test':
                $envConf = require($basePath.'test.php');
                break;
            case 'prod':
                $envConf = require($basePath.'prod.php');
                break;
        }
        if(!is_null($envConf)) Context::setConfContext($envConf);
    }

    /**
     * 是否静态资源不做处理
     */
    private function needNginx(string $requestUri)
    {
        $reqSuffixArr = explode(".",$requestUri);
        if($reqSuffixArr) {
            $reqSuffix = $reqSuffixArr[count($reqSuffixArr)-1];
            $staticResource = ['js','css','jpg','jpeg','png','gif','woff'];
            if(in_array($reqSuffix, $staticResource)) {
                return false;
            }
            return true;
        } else {
            return true;
        }
        
    }
}
