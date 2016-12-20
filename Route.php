<?php
/**
 * Route Class
 *
 * @package     Spark MVC
 * @subpackage  Spark\core
 * @category    core
 * @author      ult-ux@outlook.com
 * @link        http://example.com
 */
class Route
{
    /**
    * 已设置的路由规则
    *
    * @var array
    */
    private static $routes;

    /**
    * 类的静态实例化
    *
    * @var object
    */
    private static $instance;

    /**
    * 允许的路由请求方法
    *
    * @var array
    */
    private static $allowed_method = array('get', 'post', 'delete', 'head', 'put', 'options', 'connect');

    /**
    * 允许的路由请求方法
    *
    * @var array
    */
    private static $args_types = array(
        'num' => '[0-9]+', // 纯数字
        'any' => '[a-z_0-9]+', // 数字、字母和下划线
        'all' => '[.]+' // 除换行符外的任何字符
    );

    /**
    * uri ，由 dispatch() 方法设置
    *
    * @var string
    */
    private $uri;

    /**
     * 静态实例化
     */
    public function __construct()
    {
        self::$instance = &$this;
    }
    
    /**
     * 动态方法设置路由规则
     *
     * @param   string  $method     调用的方法名称
     * @param   array   $arguments  参数
     * @return  object
     */
    public function __call($method, $arguments)
    {
        return self::setRoutes($method, $arguments);
    }

    /**
     * 静态方法设置路由规则
     *
     * @param   string  $method     调用的方法名称
     * @param   array   $arguments  参数
     * @return  object
     */
    public static function __callStatic($method, $arguments)
    {
        return self::setRoutes($method, $arguments);
    }

    /**
     * 设置路由规则
     *
     * @param   string  $method     调用的方法名称
     * @param   array   $arguments  参数
     * @return  object
     */
    private static function setRoutes($method, $arguments)
    {
        if (in_array($method, self::$allowed_method)) {
            if (!isset($arguments[1])) {
                trigger_error('Undefined response in the rule: \''.$arguments[0].'\'', E_USER_WARNING);
            }
            if (is_array(@$arguments[1])) {
                self::$routes[$method][$arguments[0]]['response'] = array(
                    'class' => $arguments[1][0],
                    'method' => $arguments[1][1]
                );
            } else {
                self::$routes[$method][$arguments[0]]['response'] = @$arguments[1];
            }
            if (isset($arguments[2])) {
                self::$routes[$method][$arguments[0]]['parameters'] = $arguments[2];
            }
        } else {
            trigger_error('Request method without permission: '.$method.'()', E_USER_WARNING);
        }
        return self::$instance;
    }

    /**
     * 设置允许的路由请求方法
     *
     * @param   array   $methods    array('get', 'post', 'delete', 'head', 'put', 'options', 'connect')
     * @return  object
     */
    public function setAllowedMethod($methods)
    {
        self::$allowed_method = array_intersect(self::$allowed_method, $methods);
        return $this;
    }

    /**
     * 设置参数变量类型正则表达式
     *
     * @param   array   $args_types 参数的变量类型正则表达式
     * @return  object
     */
    public function setArgsTypes($args_types)
    {
        self::$args_types = array_merge(self::$args_types, $args_types);
        return $this;
    }

    /**
     * 设置 uri 请求字符串，默认处理 REQUEST_URI
     *
     * @param   string  $uri    uri
     * @param   string  $uri_suffix uri 路径后缀
     * @return  object
     */
    public function setUri($uri = null, $uri_suffix = '.html')
    {
        // 设置默认的 URI
        if ($uri == null) {
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $uri = preg_replace(['/^\/'.preg_quote($_SERVER['SCRIPT_NAME'], '/').'/is', '/'.preg_quote($uri_suffix).'$/is'], '', $uri);
        }
        $this->uri = $uri;
        return $this;
    }

    /**
     * 路由派遣
     *
     * @param   string  $uri        uri
     * @param   string  $method     请求方法
     * @param   string  $uri_suffix uri 路径后缀
     * @return  array|boolean
     */
    public function dispatch($uri = null, $method = null, $uri_suffix = '.html')
    {
        $this->setUri($uri, $uri_suffix);
        // 设置默认的请求方法
        if ($method == null) {
            $method = $_SERVER['REQUEST_METHOD'];
        }
        $method = strtolower($method);
        // 如果没有路由规则直接返回 FALSE
        if (!isset(self::$routes[$method])) {
            trigger_error('Undefined any rules', E_USER_WARNING);
            return false;
        }
        // 根据请求方法获取路由规则集合
        $rules = self::$routes[$method];
        // 如果 uri 请求在规则中不含参数表达式，则直接返回请求
        if (in_array($this->uri, array_keys($rules))) {
            return $rules[$this->uri];
        }
        // 匹配路由规则获取请求
        return $this->matchRules($rules);
    }

    /**
     * 逐条匹配路由规则，如果成功匹配则返回规则的请求数组，反之返回 FALSE
     *
     * @param   array   $rules  路由规则集合
     * @return  array|boolean
     */
    private function matchRules($rules)
    {
        foreach ($rules as $key => $value) {
            // 解析路由规则，返回规则的正则表达式和参数数组
            $rule = $this->parseRule($key);
            // 如果匹配到规则则直接返回请求，不再继续匹配
            if (preg_match($rule['pattern'], $this->uri, $matches)) {
                // 去掉第一个匹配结果，剩下的就是参数，数组顺序与规则的参数顺序一致
                array_shift($matches);
                if ($rule['args']) {
                    // 遍历规则参数，如果在 uri 中取到相应的参数值则赋值到请求参数
                    foreach ($rule['args'] as $index => $name) {
                        if (isset($matches[$index])) {
                            $value['parameters'][$name] = $matches[$index];
                        }
                    }
                }
                return $value;
            }
        }
        return false;
    }

    /**
     * 解析一条路由规则，返回规则正则表达式和参数数组
     *
     * @param   string  $rule   规则
     * @return  array
     */
    private function parseRule($rule)
    {
        // 将规则转换为分段数组
        $segments = explode('/', $rule);
        // 设置默认的参数返回数组
        $args = array();
        // 获取参数类型
        $args_types = implode('|', array_keys(self::$args_types));
        // 遍历每一个分段，匹配并拾取参数，根据参数类型转换正则表达式
        foreach ($segments as $key => $value) {
            if (preg_match('/^('.$args_types.')\:([a-z_0-9]+)(\?)?$/is', $value, $matches)) {
                array_shift($matches);
                $segments[$key] = '('.self::$args_types[$matches[0]].')';
                // 如果参数后缀为 ? 则模糊匹配这个参数
                if (isset($matches[2])) {
                    $segments[$key] = '?'.$segments[$key].'?';
                }
                $args[] = $matches[1];
            }
        }
        $result['pattern'] = '/^'.implode('\/', $segments).'$/is';
        $result['args'] = $args;
        return $result;
    }
}
