<?php
/**
 * 注意：未加入事务功能！！！！！！！！！！
 * mysql数据库类、单例模式
 * 使用须知：
 * 以数组形式传入参数，并且以参数组成数组
 * select语句以数组形式返回结果集
 * insert，update，dalete必须传入参数，返回受影响的行数
 * 使用形式：
 * $sql = 'insert into test(name,age) values(?,?)';参数位置以?代替
 * $arr = array($var1, $var2...);参数以引用传值形式构成数组
 * $db = DB::getInstance('localhost','root','','db','utf8');如果省略参数，
 * 将传入默认参数
 * $db->query($sql, $arr,[ 'all' | 'row' ]);
 */
class DB{
    private static $_instance = null;
    private $mysqli; //mysqli的实例
    private $stmt;
    private $result;
    public $insertId; //执行insert语句时，存贮最后插入记录的id号
    public $message = null;  //存贮错误信息;
    
    /**
     * 防止用new实例化
     */
    private function __construct($dbHost, $dbUser, $dbPassword, $dbName, $dbCharset){
        try {
            $this->mysqli = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
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
    public static function getInstance($dbHost = 'localhost', $dbUser = 'root',$dbPassword = '', $dbName = 'info', $dbCharset = 'utf8'){
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self($dbHost, $dbUser, $dbPassword, $dbName, $dbCharset);
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


// header('content-type: text/html; charset=utf-8');
// $db = DB::getInstance();
// $id = 7;
// $name = '猕猴桃';
// $num = 7;
// $price = 5;
// $param = array(&$name,&$id);//&$id,&$name,&$num,&$price
// var_dump($db->query('update test set name = ? where id =?',$param));
// var_dump($db->query('select * from test'));



/**
* 这个是网上找的，供参考
* MyPDO
*/ 
class MyPDO {
    protected static $_instance = null;
    protected $dbName = '';
    protected $dsn;
    protected $dbh;
    /**
    * 构造
    *
    * @return MyPDO
    */
    private function __construct($dbHost, $dbUser, $dbPasswd, $dbName, $dbCharset) {
    	try {             
    		$this->dsn = 'mysql:host='.$dbHost.';dbname='.$dbName;
    		$this->dbh = new PDO($this->dsn, $dbUser, $dbPasswd);
    		$this->dbh->exec('SET character_set_connection='.$dbCharset.', character_set_results='.$dbCharset.', character_set_client=binary');
    	} catch (PDOException $e) {
    		$this->outputError($e->getMessage());
    	}
    }
    /**
    * 防止克隆
    *
    */
    private function __clone() {}
    /**
    * Singleton instance
    *
    * @return Object
    */
    public static function getInstance($dbHost, $dbUser, $dbPasswd, $dbName, $dbCharset){
    	if (self::$_instance === null) {
    		self::$_instance = new self($dbHost, $dbUser, $dbPasswd, $dbName, $dbCharset);
    	}
    	return self::$_instance;
    }
    /**
    * Query 查询
    *
    * @param String $strSql SQL语句
    * @param String $queryMode 查询方式(All or Row)
    * @param Boolean $debug
    * @return Array
    */
    public function query($strSql, $queryMode = 'All', $debug = false){
    	if ($debug === true) $this->debug($strSql);
    	$recordset = $this->dbh->query($strSql);
    	$this->getPDOError();
    	if ($recordset) {
    		$recordset->setFetchMode(PDO::FETCH_ASSOC);
    		if ($queryMode == 'All') {
    			$result = $recordset->fetchAll();
    		} elseif ($queryMode == 'Row') {
    			$result = $recordset->fetch();
    		}
    	} else {
    		$result = null;
    	}
    	return $result;
    }
    /**
    * Update 更新
    *
    * @param String $table 表名
    * @param Array $arrayDataValue 字段与值
    * @param String $where 条件
    * @param Boolean $debug
    * @return Int
    */
    public function update($table, $arrayDataValue, $where = '', $debug = false){
    	$this->checkFields($table, $arrayDataValue);
    	if ($where) {
    		$strSql = '';
    		foreach ($arrayDataValue as $key => $value) {
    			$strSql .= ", `$key`='$value'";
    		}
    		$strSql = substr($strSql, 1);
    		$strSql = "UPDATE `$table` SET $strSql WHERE $where";
    	} else {
    		$strSql = "REPLACE INTO `$table` (`".implode('`,`', array_keys($arrayDataValue))."`) VALUES ('".implode("','", $arrayDataValue)."')";
    	}
    	if ($debug === true) $this->debug($strSql);
    	$result = $this->dbh->exec($strSql);
    	$this->getPDOError();
    	return $result;
    }
    /**
    * Insert 插入
    *
    * @param String $table 表名
    * @param Array $arrayDataValue 字段与值
    * @param Boolean $debug
    * @return Int
    */
    public function insert($table, $arrayDataValue, $debug = false){
    	$this->checkFields($table, $arrayDataValue);
    	$strSql = "INSERT INTO `$table` (`".implode('`,`', array_keys($arrayDataValue))."`) VALUES ('".implode("','", $arrayDataValue)."')";
    	if ($debug === true) $this->debug($strSql);
    	$result = $this->dbh->exec($strSql);
    	$this->getPDOError();
    	return $result;
    }
    /**
    * Replace 覆盖方式插入
    *
    * @param String $table 表名
    * @param Array $arrayDataValue 字段与值
    * @param Boolean $debug
    * @return Int
    */
    public function replace($table, $arrayDataValue, $debug = false)     {
    	$this->checkFields($table, $arrayDataValue);
    	$strSql = "REPLACE INTO `$table`(`".implode('`,`', array_keys($arrayDataValue))."`) VALUES ('".implode("','", $arrayDataValue)."')";
    	if ($debug === true) $this->debug($strSql);
    	$result = $this->dbh->exec($strSql);
    	$this->getPDOError();
    	return $result;
    }
    /**
    * Delete 删除
    *
    * @param String $table 表名
    * @param String $where 条件
    * @param Boolean $debug
    * @return Int
    */
    public function delete($table, $where = '', $debug = false)     {
    	if ($where == '') {
    		$this->outputError("'WHERE' is Null");
    	} else {
    		$strSql = "DELETE FROM `$table` WHERE $where";
    		if ($debug === true) $this->debug($strSql);
    		$result = $this->dbh->exec($strSql);
    		$this->getPDOError();
    		return $result;
    	}
    }
    /**
    * execSql 执行SQL语句
    *
    * @param String $strSql
    * @param Boolean $debug
    * @return Int
    */
    public function execSql($strSql, $debug = false)     {
    	if ($debug === true) $this->debug($strSql);
    	$result = $this->dbh->exec($strSql);
    	$this->getPDOError();
    	return $result;
    }
    /**
    * 获取字段最大值
    *
    * @param string $table 表名
    * @param string $field_name 字段名
    * @param string $where 条件
    */
    public function getMaxValue($table, $field_name, $where = '', $debug = false)     {
    	$strSql = "SELECT MAX(".$field_name.") AS MAX_VALUE FROM $table";
    	if ($where != '') $strSql .= " WHERE $where";
    	if ($debug === true) $this->debug($strSql);
    	$arrTemp = $this->query($strSql, 'Row');
    	$maxValue = $arrTemp["MAX_VALUE"];
    	if ($maxValue == "" || $maxValue == null) {
    		$maxValue = 0;
    	}
    	return $maxValue;
    }
    /**
    * 获取指定列的数量
    *
    * @param string $table
    * @param string $field_name
    * @param string $where
    * @param bool $debug
    * @return int
    */
    public function getCount($table, $field_name, $where = '', $debug = false)     {
    	$strSql = "SELECT COUNT($field_name) AS NUM FROM $table";
    	if ($where != '') $strSql .= " WHERE $where";
    	if ($debug === true) $this->debug($strSql);
    	$arrTemp = $this->query($strSql, 'Row');
    	return $arrTemp['NUM'];
    }
    /**
    * 获取表引擎
    *
    * @param String $dbName 库名
    * @param String $tableName 表名
    * @param Boolean $debug
    * @return String
    */
    public function getTableEngine($dbName, $tableName)     {
    	$strSql = "SHOW TABLE STATUS FROM $dbName WHERE Name='".$tableName."'";
    	$arrayTableInfo = $this->query($strSql);
    	$this->getPDOError();
    	return $arrayTableInfo[0]['Engine'];
    }
    /**
    * beginTransaction 事务开始
    */
    private function beginTransaction()     {
    	$this->dbh->beginTransaction();
    }
    /**
    * commit 事务提交
    */
    private function commit()     {
    	$this->dbh->commit();
    }
    /**
    * rollback 事务回滚
    */
    private function rollback(){
    	$this->dbh->rollback();
    }
    /**
    * transaction 通过事务处理多条SQL语句
    * 调用前需通过getTableEngine判断表引擎是否支持事务
    *
    * @param array $arraySql
    * @return Boolean
    */
    public function execTransaction($arraySql)     {
    	$retval = 1;
    	$this->beginTransaction();
    	foreach ($arraySql as $strSql) {
    		if ($this->execSql($strSql) == 0) $retval = 0;
    	}
    	if ($retval == 0) {
    		$this->rollback();
    		return false;
    	} else {
    		$this->commit();
    		return true;
    	}
    }
    /**
    * checkFields 检查指定字段是否在指定数据表中存在
    *
    * @param String $table
    * @param array $arrayField
    */
    private function checkFields($table, $arrayFields)     {
    	$fields = $this->getFields($table);
    	foreach ($arrayFields as $key => $value) {
    		if (!in_array($key, $fields)) {
    			$this->outputError("Unknown column `$key` in field list.");
    		}
    	}
    }
    /**
    * getFields 获取指定数据表中的全部字段名
    *
    * @param String $table 表名
    * @return array
    */
    private function getFields($table)     {
    	$fields = array();
    	$recordset = $this->dbh->query("SHOW COLUMNS FROM $table");
    	$this->getPDOError();
    	$recordset->setFetchMode(PDO::FETCH_ASSOC);
    	$result = $recordset->fetchAll();
    	foreach ($result as $rows) {
    		$fields[] = $rows['Field'];
    	}
    	return $fields;
    }
    /**
    * getPDOError 捕获PDO错误信息
    */
    private function getPDOError()     {
    	if ($this->dbh->errorCode() != '00000') {
    		$arrayError = $this->dbh->errorInfo();
    		$this->outputError($arrayError[2]);
    	}
    }
    /**
    * debug
    *
    * @param mixed $debuginfo
    */
    private function debug($debuginfo)     {
    	var_dump($debuginfo);
    	exit();
    }
    /**
    * 输出错误信息
    *
    * @param String $strErrMsg
    */
    private function outputError($strErrMsg)     {
    	throw new Exception('MySQL Error: '.$strErrMsg);
    }
    /**
    * destruct 关闭数据库连接
    */
    public function destruct()     {
    	$this->dbh = null;
    }
}
?>