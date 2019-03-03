<?php
namespace PracticeMP\DB;
/**
 * 注意：未加入事务功能！！！！！！！！！！
 * mysql数据库类、单例模式
 * 使用须知：
 * 以数组形式传入参数，并且以参数组成数组
 * select语句以数组形式返回结果集
 * insert，update，dalete必须传入参数，返回受影响的行数
 * 使用形式：
 * // 以单例模式获取实例。如果省略参数，将传入默认参数。
 * $sm = SimpleMysql::getInstance('localhost','root','','test', 3306,'utf8');
 * // 参数化查询 参数位置以?代替，语句最后不要用分号，这是 prepare 的要求。
 * $sql = 'insert into test(name,age) values(?,?)';
 * // 将参数构成数组形式
 * $data = array($var1, $var2...);
 * // 最后的 all 或 row 只有在 select 查询时才有用。
 * $result = $sm->query($sql, $data,[ 'all' | 'row' ]); 
 * 一定要对 $result === false 做判断，因为 false 的同时也会断开数据库连接。
 */
class SimpleMysql{
    private static $_instance = null;
    private $mysqli; //mysqli的实例
    private $stmt;
    private $result;
    public $insertId; //执行insert语句时，存贮最后插入记录的id号
    public $message = null;  //存贮错误信息;
    
    /**
     * 防止用new实例化
     */
    private function __construct($dbHost, $dbUser, $dbPassword, $dbName, $dbPort, $dbCharset){
        try {
            $this->mysqli = new \mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
            $this->mysqli->set_charset($dbCharset);
        } catch (Throwable $e) {
            $this->outputError($e->getMessage());
        }
    }

    /**
     * 防止克隆
     */
    private function __clone(){}

    /**
     * 用单例模式获取实例
     * @param  string 数据库地址
     * @param  string 用户名
     * @param  string 用户密码
     * @param  string 数据库名称
     * @param  string 字符集
     * @return object DB类的实例
     */
    public static function getInstance($dbHost = 'localhost', $dbUser = 'root',$dbPassword = '', $dbName = 'test', $dbPort = 3306, $dbCharset = 'utf8'){
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self($dbHost, $dbUser, $dbPassword, $dbName, $dbPort, $dbCharset);
        }
        return self::$_instance;
    }


    /**
     * 注意：绑定的参数不能是字段名或者表名，详情看prepare文档。
     * @param  str      $strSql  预处理语句
     * @return boolean           成功返回真，失败返回假
     */
    private function prepare($strSql){
        $this->stmt = $this->mysqli->stmt_init();
        if (! $this->stmt->prepare($strSql)) {
            $this->mysqli->close();
            $this->message = 'DB prepare error!'.$this->stmt->error;
            return false;
        }
        return true;
    }

    public function query($strSql, array $params = array(), $queryMode = 'all'){
        if (!in_array(strtolower(substr($strSql,0,6)),array('select','insert','update','delete'))) {
            $this->message = 'only "select" or "insert" or "update" or "delete" can be used.';
            return false;
        } elseif (!$this->prepare($strSql)){
            return false;
        } elseif (strtolower(substr($strSql,0,6)) === 'select') {
            return $this->select($params, $queryMode);
        } elseif (in_array(strtolower(substr($strSql,0,6)),array('insert','update','delete'),TRUE)) {
            return $this->insertUpdateDelete($params);
        }       
    }

    private function select($params, $queryMode){
        /**
         * 判断传入的 结果集形式 的 字符串 是否是 'all' 或者 'row' 中的一个
         * all 代表输出所有结果集，结果集是一个二维数组
         * row 代表只输出结果集中的第一行，结果集是一个一维数组
         * 再与有无参数传入结合形成四种判断条件
         */
        if (!is_string($queryMode)||(strtolower($queryMode)!=='all'&&strtolower($queryMode)!=='row')) {
            $this->stmt->close();
            $this->mysqli->close();
            $this->message = 'DB query mode error';
            return false;
        }
        if (empty($params) && $queryMode === 'all') {
            if(!$this->stmt->execute()){
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query execute error';
                return false;
            }           
            if(!$this->result = $this->stmt->get_result()){
                $this->stmt->free_result();
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query get result error';
                return false;
            }
            $rows = array();
            while ($row = $this->result->fetch_assoc()) {
                $rows[] = $row;
            }
            $this->stmt->free_result();
            $this->stmt->close();
            return $rows;
        }
        if (empty($params) && $queryMode === 'row') {
            if(!$this->stmt->execute()){
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query execute error'.$this;
                return false;
            }           
            if(!$this->result = $this->stmt->get_result()){
                $this->stmt->free_result();
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query get result error';
                return false;
            }
            $row = null;
            $row = $this->result->fetch_assoc();
            $this->stmt->free_result();
            $this->stmt->close();
            return $row;
        }
        if (!empty($params) && $queryMode === 'all') {
            //得到以参数类型标识符组成的字符串$type
            if(!$type = $this->verifyParamsType($params)){
                return false;
            }
            //执行绑定参数的方法
            if (!$this->bindParams($type,$params)) {
               return false;
            } 
            //绑定成功后的过程和“无参数传入及all形式”的分支一样
            if(!$this->stmt->execute()){
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query execute error';
                return false;
            }           
            if(!$this->result = $this->stmt->get_result()){
                $this->stmt->free_result();
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query get result error';
                return false;
            }
            $rows = array();
            while ($row = $this->result->fetch_assoc()) {
                $rows[] = $row;
            }
            $this->stmt->free_result();
            $this->stmt->close();
            return $rows;
        }
        if (!empty($params) && $queryMode === 'row') {
            //得到以参数类型标识符组成的字符串$type
            if(!$type = $this->verifyParamsType($params)){
            return false;
            }
            // 执行绑定参数的方法
            if (!$this->bindParams($type,$params)) {
               return false;
            } 
            // 绑定成功后的过程和“无参数传入及row形式”的分支一样
            if(!$this->stmt->execute()){
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query execute error';
                return false;
            }           
            if(!$this->result = $this->stmt->get_result()){
                $this->stmt->free_result();
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'DB query get result error';
                return false;
            }
            $row = null;
            $row = $this->result->fetch_assoc();
            $this->stmt->free_result();
            $this->stmt->close();
            return $row;
        }
    }

    /**
     * insert、update、delete 都可以用这个函数
     * 返回值为mysqli_stmt_affected_rows的返回值
     * @param  array
     * @return int or null 
     */
    private function insertUpdateDelete($params){
        if (!isset($params) || empty($params)) {
            $this->myslqi->close();
            $this->message = 'DB parameters for insert is empty!';
            return false;
        }
        //得到以参数类型标识符组成的字符串$type
        if(!$type = $this->verifyParamsType($params)){
            return false;
        }
        //执行绑定参数的方法
        if (!$this->bindParams($type,$params)) {
           return false;
        }        
        //绑定后执行sql预处理语句
        if(!$this->stmt->execute()){
            $this->stmt->close();
            $this->mysqli->close();
            $this->message = 'DB query execute error';
            return false;
        }
        $num = null;
        $num = $this->stmt->affected_rows;
        $this->insertId = $this->stmt->insert_id;
        $this->stmt->close();
        return $num;
    }



    /**
     * 获取以参数的类型标识符组成的字符串，便于绑定参数时使用
     * @param  array 以传入sql语句的参数组成的数组
     * @return string 以参数的类型标识符组成的字符串，便于绑定参数时使用
     */
    private function verifyParamsType($params){
        $type = '';
        foreach ($params as $value) {
            if (is_string($value)) {
                $type .= 's';
            }else if(is_int($value)){
                $type .= 'i';
                /**
                 * 注意：这个判断未必是对的，暂定。
                 * 根据php文档下面的个别观点，如果传入的整数大于特定大小，
                 * 将会以字符串形式传入。
                 */
            }else if (is_double($value)) {
                $type .= 'd';
            }else if (is_resource($value)&&get_resource_type($value) === 'file') {
                $type .= 'b';
                /**
                 * 注意：这个判断未必是对的，暂定。
                 * 思路：blob是二进制类型，存储时需要先用fopen打开，存储的对象
                 * 就是这个fopen函数返回的资源句柄，所以暂时用is_resource和
                 * get_resource_type判断。另外如果是要存储文件，可以先用
                 * get_file_contents()将文件转换成字符串，然后进行存储。
                 */
            }else{
                $this->stmt->close();
                $this->mysqli->close();
                $this->message = 'The type of DB query parameter error!';
                return false;
            }
        }
        return $type;
    }


    /**
     * 注意：绑定的参数不能是字段名或者表名，详情看prepare文档。
     * @param  string $type   由参数类型标识符组成的字符串
     * @param  array  $params 由参数组成的数组
     * @return boolean         成功返回真，失败返回假
     */
    private function bindParams($type,$params){
        //把$type压入数组$params的第一位
        array_unshift($params, $type);
        //这里的foreach需要引用传值
        foreach ($params as &$value) {  //这一行的引用是必须的
            $params1[] = &$value;       //这一行的引用也是必须的
        }
        //以回调函数的形式将参数传入绑定函数
        if(!call_user_func_array(array($this->stmt,"bind_param"), $params1)){
            $this->stmt->close();
            $this->mysqli->close();
            $this->message = 'DB bind parameters error!';
            return false;
        }else{
            return true;
        }
    }

    private function outputError($str){
        throw new Exception($str);        
    }
}
?>