<?php
namespace think\generator\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\console\Table;
use think\Container;
use think\Db;
use think\facade\Config;
use think\facade\Env;
use think\Loader;

class Generate extends Command
{
    /**
     * 配置
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('generate')
            ->addOption('config', 'c', Option::VALUE_OPTIONAL, '配置名称，默认为 generator', 'generator')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, '要生成的table，多个用,隔开, 默认为所有table')
            ->addOption('tablePrefix', 'p', Option::VALUE_OPTIONAL, 'table前缀，多个用,隔开')
            ->addOption('ignoreFields', 'i', Option::VALUE_OPTIONAL, "忽略的字段，不生成搜索器")
            ->addOption('except', 'e', Option::VALUE_OPTIONAL, '要排除的table，多个用,隔开')
            ->addOption('type', null, Option::VALUE_OPTIONAL, "要生成的类型，多个用,隔开,如 m,v,c,p,s,d\n m -- model, v -- validate, c -- controller, p -- postmanJson, s -- searchAttr, d -- model doc")
            ->addOption('templateDir', null, Option::VALUE_OPTIONAL, "自定义模板文件夹路径，必须有 model.tpl, controller.tpl, validate.tpl等文件，使用tp模板语法")
            ->addOption('mModule', null, Option::VALUE_OPTIONAL, "模型模块名")
            ->addOption('vModule', null, Option::VALUE_OPTIONAL, "校验器模块名")
            ->addOption('cModule', null, Option::VALUE_OPTIONAL, "控制器模块名")
            ->addOption('mLayer', null, Option::VALUE_OPTIONAL, "模型分层")
            ->addOption('vLayer', null, Option::VALUE_OPTIONAL, "校验器分层")
            ->addOption('cLayer', null, Option::VALUE_OPTIONAL, "控制器分层")
            ->addOption('mBase', null, Option::VALUE_OPTIONAL, "模型继承类，如 app\\common\\model\\EventModel")
            ->addOption('vBase', null, Option::VALUE_OPTIONAL, "校验器继承")
            ->addOption('cBase', null, Option::VALUE_OPTIONAL, "控制器继承类")
            ->addOption('db', null, Option::VALUE_OPTIONAL, "数据库配置文件名")
            ->addOption('dryRun', 'd', Option::VALUE_OPTIONAL, "只执行，不保存", false)
            ->addOption('force', 'f', Option::VALUE_OPTIONAL, "覆盖已存在文件", false)
            ->addOption('pName', null, Option::VALUE_OPTIONAL, "PostMan 项目名称，默认使用 数据库名")
            ->addOption('pHost', null, Option::VALUE_OPTIONAL, "PostMan API请求前缀，默认使用 api_prefix 环境变量")
            ->setDescription('Auto generate model file');
    }

    /**
     * 返回默认值
     *
     * @param string $type
     * @param mixed $default
     * @return mixed
     */
    public static function parseFieldDefaultValue($type, $default)
    {
        switch (strtolower($type)) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
                return (int) $default;
                break;
            case 'float':
            case 'double':
            case 'decimal':
                return (float) $default;
            case 'char':
            case 'varchar':
            default:
                return var_export($default, true);
                break;
        }
    }

    /**
     * 生成postman API请求
     *
     * @param strng $name
     * @param string $method
     * @param string $host
     * @param string $path
     * @param string $body
     * @return array
     */
    public static function generatePostmanRequest($name, $method, $host, $path, $body = '')
    {
        $header = [];
        if ($method == 'POST') {
            $header = [
                [
                    'key'   => 'Content-Type',
                    'name'  => 'Content-Type',
                    'value' => 'application/json',
                    'type'  => 'text',
                ],
            ];
        }
        if (!is_string($body)) {
            $body = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $pathArr = explode('/', ltrim($path, '/'));
        return [
            'name'    => $name,
            'request' => [
                'method' => $method,
                'header' => $header,
                'body'   => [
                    'mode' => 'raw',
                    'raw'  => $body,
                ],
                'url'    =>
                [
                    'raw'  => $host . $path,
                    'host' => [
                        $host,
                    ],
                    'path' => $pathArr,
                ],
            ],
        ];
    }

    /**
     * 解析配置
     *
     * @param Input $input
     * @param Output $output
     * @return array
     */
    public static function parseConfig(Input $input, Output $output)
    {
        $defaultConfig = [
            // 要生成的表名
            'table'  => null,

            // 要排除的表名
            'except' => [],

            // 要生成的类型
            // 'type'   => ['m', 'v', 'c', 'p', 's'],
            'type'   => [],

            // 是否 dryRun
            'dryRun' => false,

            // 是否覆盖已有文件
            'force'   => false,

            // 数据库配置
            'dbConfigName' => 'database',
            // 数据库名称
            'databaseName' => '',
            // 模型 表的数据库前缀
            'dbNamePrefix' => '',

            // 表前缀，不参与文件命名和类命名
            'tablePrefix'  => [],

            // 忽略的字段名
            'ignoreFields' => [],

            // 字段类型映射
            'varcharFieldMap'   => ['varchar', 'char', 'text', 'mediumtext'],
            'enumFieldMap'      => ['tinyint'],
            'timestampFieldMap' => ['date', 'datetime'],
            'numberFieldMap'    => ['int'],

            'idFieldMap' => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],

            // 字段类型匹配
            'createFieldMap' => ['createtime', 'create_time', 'createdtime', 'created_time', 'createat', 'create_at', 'createdat', 'created_at'],
            'updateFieldMap' => ['updatetime', 'update_time', 'updatedtime', 'updated_time', 'updateat', 'update_at', 'updatedat', 'updated_at'],
            'deleteFieldMap' => ['deletetime', 'delete_time', 'deletedtime', 'deleted_time', 'deleteat', 'delete_at', 'deletedat', 'deleted_at'],
            'passwordFieldMap' => ['password', 'pwd', 'encrypt'],

            'intFieldTypeList' => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'boolean', 'serial'],
            'floatFieldTypeList' => ['decimal', 'float', 'double', 'real'],

            // 自定义模板路径
            'templateDir' => '',

            // 模型模块
            'mModule' => 'common',
            // 校验器模块
            'vModule' => 'common',
            // 控制器模块
            'cModule' => 'index',

            // 模型分层
            'mLayer' => '',
            // 校验器分层
            'vLayer' => '',
            // 控制器分层
            'cLayer' => '',

            // 模型继承类
            'mBase'   => 'think\\Model',
            // 校验器继承类
            'vBase'   => 'think\\Validate',
            // 控制器继承类
            'cBase'   => 'think\\Controller',

            // 模型继承类名
            'mBaseName' => 'Model',
            // 校验器继承类名
            'vBaseName' => 'Validate',
            // 控制器继承类名
            'cBaseName' => 'Controller',

            // postman 项目名
            'projectName' => '',
            // postman api前缀
            'postmanAPIHost' => '{{api_prefix}}',
        ];

        // 加载配置文件
        $commandConfig = Config::get('generator.');
        $defaultConfig = array_merge($defaultConfig, $commandConfig ?: []);

        // 解析数据库配置
        if ($input->hasOption('db')) {
            $defaultConfig['dbConfigName'] = $input->getOption('db');
            $dbConfig     = Config::get("{$defaultConfig['dbConfigName']}.");
            if (is_array($dbConfig)) {
                Db::init($dbConfig);
            } else {
                $output->writeln("\n\n数据库配置错误");
                return;
            }
        }

        $defaultConfig['databaseName'] = Config::get("{$defaultConfig['dbConfigName']}.database");
        $defaultConfig['dbNamePrefix'] = $defaultConfig['databaseName'] == Config::get("database.database") ? '' : ($defaultConfig['databaseName'] . '.');

        $args = [
            // 要生成的表名
            'table'   => $input->hasOption('table') ? explode(',', $input->getOption('table')) : $defaultConfig['table'],

            // 要排除的表名
            'except'  => $input->hasOption('except') ? explode(',', $input->getOption('except')) : $defaultConfig['except'],

            // 要生成的类型
            'type'    => $input->hasOption('type') ? explode(',', $input->getOption('type')) : $defaultConfig['type'],

            // 是否 dryRun
            'dryRun'  => $input->hasOption('dryRun'),

            // 是否覆盖已有文件
            'force'   => $input->hasOption('force'),

            // 表前缀，不参与文件命名和类命名
            'tablePrefix'  => $input->hasOption('tablePrefix') ? explode(',', $input->getOption('tablePrefix')) : $defaultConfig['tablePrefix'],

            // 忽略的字段名
            'ignoreFields' => $input->hasOption('ignoreFields') ? explode(',', $input->getOption('ignoreFields')) : $defaultConfig['ignoreFields'],

            'templateDir' => $input->hasOption('templateDir') ? $input->getOption('templateDir') : $defaultConfig['templateDir'],

            // 模型模块
            'mModule' => $input->hasOption('mModule') ? $input->getOption('mModule') : $defaultConfig['mModule'],
            // 校验器模块
            'vModule' => $input->hasOption('vModule') ? $input->getOption('vModule') : $defaultConfig['vModule'],
            // 控制器模块
            'cModule' => $input->hasOption('cModule') ? $input->getOption('cModule') : $defaultConfig['cModule'],

            // 模型分层
            'mLayer' => $input->hasOption('mLayer') ? $input->getOption('mLayer') : $defaultConfig['mLayer'],
            // 校验器分层
            'vLayer' => $input->hasOption('vLayer') ? $input->getOption('vLayer') : $defaultConfig['vLayer'],
            // 控制器分层
            'cLayer' => $input->hasOption('cLayer') ? $input->getOption('cLayer') : $defaultConfig['cLayer'],

            // 模型继承类
            'mBase'   => $input->hasOption('mBase') ? $input->getOption('mBase') : $defaultConfig['mBase'],
            // 校验器继承类
            'vBase'   => $input->hasOption('vBase') ? $input->getOption('vBase') : $defaultConfig['vBase'],
            // 控制器继承类
            'cBase'   => $input->hasOption('cBase') ? $input->getOption('cBase') : $defaultConfig['cBase'],

            // postman 项目名
            'projectName' => $input->hasOption('pName') ? $input->getOption('pName') : $defaultConfig['dbConfigName'],
            // postman api前缀
            'postmanAPIHost' => $input->hasOption('pHost') ? $input->getOption('pHost') : $defaultConfig['postmanAPIHost'],
        ];

        if ($args['except']) {
            $args['except'] = array_filter($args['except']);
        }

        if ($args['type']) {
            $args['type'] = array_filter($args['type']);
        }

        if ($args['tablePrefix']) {
            $args['tablePrefix'] = array_filter($args['tablePrefix']);
        }

        if ($args['ignoreFields']) {
            $args['ignoreFields'] = array_filter($args['ignoreFields']);
        }

        if ($args['mLayer']) {
            $args['mLayer'] = '\\' . ltrim($args['mLayer'], '\\');
        }

        if ($args['vLayer']) {
            $args['vLayer'] = '\\' . ltrim($args['vLayer'], '\\');
        }

        if ($args['cLayer']) {
            $args['cLayer'] = '\\' . ltrim($args['cLayer'], '\\');
        }

        if ($args['mBase']) {
            $args['mBaseName'] = substr(strrchr($args['mBase'], '\\'), 1);
        }

        if ($args['vBase']) {
            $args['vBaseName'] = substr(strrchr($args['vBase'], '\\'), 1);
        }

        if ($args['cBase']) {
            $args['cBaseName'] = substr(strrchr($args['cBase'], '\\'), 1);
        }

        return array_merge($defaultConfig, $args);
    }

    /**
     * 不区分大小写的查找
     *
     * @param mixed $needle
     * @param array $haystack
     * @return boolean
     */
    public static function arrayLikeCase($needle, $haystack)
    {
        foreach ($haystack as $key => $value) {
            if (strpos(strtolower($needle), $value) !== false) {
                return $key;
            }
        }
        return false;
    }


    /**
     * 输出信息块
     *
     * @param Output $output
     * @param mixed $message
     * @return void
     */
    public static function writeBlock(Output $output, $message)
    {
        $output->writeln('');
        foreach ((array)$message as $msg) {
            $output->writeln($msg);
        }
        $output->writeln('');
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $config = self::parseConfig($input, $output);

        if (!count($config['type'])) {
            $output->writeln("\n\n请输入生成类型");
            return;
        }

        if ($config['mBase']) {
            $reflectionClass = new \ReflectionClass($config['mBase']);
            $config['baseModelCorrect'] = $reflectionClass->hasMethod('searchCreateTimeAttr');
        }
        if ($config['cBase']) {
            $reflectionClass = new \ReflectionClass($config['cBase']);
            $config['baseControllerCorrect'] = $reflectionClass->hasConstant('LIST_ALL_DATA');
        }
        $tableList = Db::query('SHOW TABLES');

        $generatedList = [];

        $postmanAPI = [
            'info' => [
                'name'   => $config['projectName'],
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [

            ],
        ];

        $searchFieldMapAll = [];

        foreach ($tableList as $table) {
            $tableName = current($table);

            if (!is_null($config['table']) && !in_array($tableName, $config['table'])) {
                continue;
            }

            if (!is_null($config['except']) && in_array($tableName, $config['except'])) {
                continue;
            }

            $tableColumns = Db::query('SELECT * FROM information_schema.columns WHERE table_schema = ? and table_name = ? ', [
                $config['databaseName'],
                $tableName,
            ]);

            $tableDesc = Db::query('SELECT TABLE_COMMENT FROM information_schema.TABLES where table_schema = ? and table_name = ? ', [
                $config['databaseName'],
                $tableName,
            ]);
            $tableDesc = $tableDesc[0]['TABLE_COMMENT'];

            // 搜索器 字段
            $searchField    = [];
            $varcharField   = [];
            $enumField      = [];
            $timestampField = [];
            $numberField    = [];
            $searchFieldMap = [];

            // 主键
            $pk = '';

            // 创建时间字段
            $createTime = false;
            // 更新时间字段
            $updateTime = false;
            // 软删除字段
            $deleteTime = false;

            // 没有默认值的字段
            $nodefaultFields = [];

            // 有默认值的字段
            $defaultFields = [];

            // 新增、编辑请求字段过滤
            $fieldStr = '';

            // 编辑请求字段过滤
            $updateFieldStr = '';

            // 编辑时只读字段
            $readonlyField = [];

            // 校验器字段
            $validate = [];

            // 列表隐藏字段
            $hiddenField = [];

            // 模型名
            $modelName = Loader::parseName(str_replace($config['tablePrefix'], '', $tableName), 1);
            $modelInstanceName = lcfirst($modelName . 'Instance');

            // 模型注释
            $modelDoc = '';

            $generateResult = [];
            foreach ($tableColumns as &$field) {
                if (in_array($field['DATA_TYPE'], $config['intFieldTypeList'])) {
                    $field['DATA_TYPE_IN_PHP'] = 'int';
                } else if (in_array($field['DATA_TYPE'], $config['floatFieldTypeList'])) {
                    $field['DATA_TYPE_IN_PHP'] = 'float';
                } else {
                    $field['DATA_TYPE_IN_PHP'] = 'string';
                }

                // 生成 model 注释
                $modelDoc .= " * @property {$field['DATA_TYPE_IN_PHP']} \${$field['COLUMN_NAME']} {$field['COLUMN_COMMENT']}\r\n";

                $name                       = $field['COLUMN_NAME'];
                $field['COLUMN_NAME_UPPER'] = Loader::parseName($name, 1);
                if ($field['COLUMN_KEY'] == 'PRI') {
                    $pk = $name;
                    $updateFieldStr .= "'{$name}' => \$id,\n";
                } else {
                    $validateKey = $name . ($field['COLUMN_COMMENT'] ? "|{$field['COLUMN_COMMENT']}" : '');

                    $validateValue = '';

                    if (!is_null($field['CHARACTER_MAXIMUM_LENGTH'])) {
                        $validateValue .= "|max:{$field['CHARACTER_MAXIMUM_LENGTH']}";
                    }

                    if (in_array($field['DATA_TYPE'], $config['intFieldTypeList'])) {
                        $validateValue .= "|number";
                    }

                    if ($validateValue) {
                        $validate[$validateKey] = ltrim($validateValue, '|');
                    }

                    // 判断时间字段
                    $isTimeField = false;
                    if (self::arrayLikeCase($name, $config['createFieldMap']) !== false) {
                        $createTime = $name;
                        // 创建时间不能更新
                        $readonlyField[] = $name;
                        $isTimeField     = true;
                    } else if (self::arrayLikeCase($name, $config['updateFieldMap']) !== false) {
                        $updateTime  = $name;
                        $isTimeField = true;
                    } else if (self::arrayLikeCase($name, $config['deleteFieldMap']) !== false) {
                        $deleteTime  = $name;
                        $isTimeField = true;
                    }

                    $isIgnored = in_array($name, $config['ignoreFields']);

                    if (!is_null($field['COLUMN_DEFAULT'])) {
                        $defaultFields[$name] = $field['COLUMN_DEFAULT'];
                        if (!$isTimeField && !$isIgnored) {
                            $defaultValue = self::parseFieldDefaultValue($field['DATA_TYPE'], $field['COLUMN_DEFAULT']);
                            $fieldStr .= "'{$name}' => {$defaultValue},\n";
                        }
                    } else {
                        $nodefaultFields[] = $name;
                        if (!$isTimeField && !$isIgnored) {
                            $fieldStr .= "'{$name}',\n";
                        }
                    }
                    // 时间戳字段和忽略字段以外的加入更新参数
                    if (!$isTimeField && !$isIgnored) {
                        $updateFieldStr .= "'{$name}' => \${$modelInstanceName}->{$name},\n";
                    }
                    // 密码字段不需要搜索
                    if (self::arrayLikeCase($name, $config['passwordFieldMap']) !== false) {
                        $hiddenField[]   = $name;
                        $readonlyField[] = $name;
                        continue;
                    }

                    // 忽略字段不需要搜索
                    if ($isIgnored) {
                        $readonlyField[] = $name;
                        continue;
                    }

                    // 搜索字段
                    if ($isTimeField) {
                        $timestampField[] = $field;
                    } else if (strpos(strtolower($name), 'id') !== false && in_array($field['DATA_TYPE'], $config['idFieldMap'])) {
                        // 关联外键
                        $enumField[]           = $field;
                        $searchField[]         = $name;
                        $searchFieldMap[$name] = 'enum';
                    } else if (in_array($field['DATA_TYPE'], $config['varcharFieldMap'])) {
                        $varcharField[]        = $field;
                        $searchField[]         = $name;
                        $searchFieldMap[$name] = 'varchar';
                    } else if (in_array($field['DATA_TYPE'], $config['enumFieldMap'])) {
                        $enumField[]           = $field;
                        $searchField[]         = $name;
                        $searchFieldMap[$name] = 'enum';
                    } else if (in_array($field['DATA_TYPE'], $config['timestampFieldMap'])) {
                        $timestampField[] = $field;
                        $searchField[]    = $name;
                    } else if (in_array($field['DATA_TYPE'], $config['numberFieldMap'])) {
                        $numberField[]         = $field;
                        $searchField[]         = $name;
                        $searchFieldMap[$name] = 'number';
                    }
                }
            }

            if ($createTime) {
                $searchField[]  = 'create_time';
            }
            if ($updateTime) {
                $searchField[]  = 'update_time';
            }
            $searchFieldStr = array_reduce($searchField, function ($carry, $item) {
                return $carry .= "'{$item}',";
            });

            $hiddenFieldStr = array_reduce($hiddenField, function ($carry, $item) {
                return $carry .= "'{$item}',";
            });

            $readonlyFieldStr = array_reduce($readonlyField, function ($carry, $item) {
                return $carry .= "'{$item}',";
            });

            $validateStr = '';

            foreach ($validate as $key => $value) {
                $validateStr .= "'{$key}' => '{$value}',\n";
            }

            $modelDoc = <<<EOF
/**
 * $modelName  Model of $tableDesc
 *
$modelDoc */
EOF;

            // 生成模型、校验器、控制器
            $view = Container::get('view');
            $data = [
                'pk'               => $pk,
                'tableName'        => $tableName,
                'tableDesc'        => $tableDesc,
                'tableColumns'     => $tableColumns,
                'modelDoc'         => $modelDoc,
                'modelName'        => $modelName,
                'modelAlias'       => $modelName . 'Model',
                'modelInstance'    => $modelInstanceName,
                'validateAlias'    => $modelName . 'Validate',
                'createTime'       => $createTime,
                'updateTime'       => $updateTime,
                'deleteTime'       => $deleteTime,
                'autoTime'         => $createTime || $updateTime || $deleteTime,
                'varcharField'     => $varcharField,
                'enumField'        => $enumField,
                'timestampField'   => $timestampField,
                'numberField'      => $numberField,
                'fieldStr'         => $fieldStr,
                'updateFieldStr'   => $updateFieldStr,
                'searchFieldStr'   => $searchFieldStr,
                'validateStr'      => $validateStr,
                'hiddenFieldStr'   => $hiddenFieldStr,
                'readonlyFieldStr' => $readonlyFieldStr,
            ];
            $view->assign(array_merge($config, $data));

            $templateDir = $config['templateDir'] ?: __DIR__ . DIRECTORY_SEPARATOR . 'tpl' . DIRECTORY_SEPARATOR;

            $mLayerDir = str_replace('\\', '/', $config['mLayer']);
            $vLayerDir = str_replace('\\', '/', $config['vLayer']);
            $cLayerDir = str_replace('\\', '/', $config['cLayer']);

            $templateFile = [
                'model.tpl'      => Env::get('app_path') . $config['mModule'] . "/model{$mLayerDir}/{$modelName}.php",
                'controller.tpl' => Env::get('app_path') . $config['cModule'] . "/controller{$cLayerDir}/{$modelName}.php",
                'validate.tpl'   => Env::get('app_path') . $config['vModule'] . "/validate{$vLayerDir}/{$modelName}.php",
            ];
            if ($output->isDebug()) {
                self::writeBlock($output, [
                    $tableName . " data:",
                    // var_export($data, true)
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
            }
            foreach ($templateFile as $tpl => $path) {
                if (!in_array($tpl[0], $config['type'])) {
                    continue;
                }
                $content = $view->fetch($templateDir . $tpl);
                if (is_file($path) && !$config['force']) {
                    $generateResult[$tpl[0]] = 'File exists';
                    continue;
                }
                if (!$config['dryRun']) {
                    $dirName = dirname($path);
                    if (!is_dir($dirName)) {
                        mkdir($dirName, 0777, true);
                    }
                    file_put_contents($path, "<?php\n" . $content);
                    $generateResult[$tpl[0]] = 'Generated';
                } else {
                    $generateResult[$tpl[0]] = 'Will generate';
                }
            }

            // 更新 modelDoc
            if (in_array('d', $config['type'])) {
                $path = $templateFile['model.tpl'];
                if ($config['dryRun']) {
                    if ($output->isVerbose()) {
                        self::writeBlock($output, [
                            $tableName . " modelDoc:",
                            $modelDoc
                        ]);
                    }
                    if (!is_file($path)) {
                        $generateResult['d'] = 'File not exists';
                    } else {
                        $generateResult['d'] = 'Will update';
                    }
                } else if (is_file($path)) {
                    $content = file_get_contents($path);

                    $content = preg_replace_callback("~(/\*.*?\*/\s+)?(class\s+" . $modelName . "\s+extends)~s", function($matches)use($modelDoc){
                        return $modelDoc . "\n" . $matches[2];
                    }, $content);

                    // $content = preg_replace_callback("~(/\*.*?\*/\s+)?class\s+" . $modelName . "\s+extends~s", function($matches){
                    //     echo "\n\n匹配结果：\n\n";
                    //     echo $matches[0];
                    //     return $matches[0];
                    // }, $content);

                    file_put_contents($path, $content);
                    $generateResult['d'] = 'Updated';
                } else {
                    $generateResult['d'] = 'File not exists';
                    if ($output->isVerbose()) {
                        self::writeBlock($output, [
                            $tableName . " modelDoc:",
                            $modelDoc
                        ]);
                    }
                }
            }

            // 生成 postman API
            if (in_array('p', $config['type'])) {
                $postmanAPI['item'][] = [
                    'name' => $modelName,
                    'item' => [
                        self::generatePostmanRequest($modelName . '列表', 'GET', $config['postmanAPIHost'], '/Index/' . $modelName . '/index'),
                        self::generatePostmanRequest('查看' . $modelName, 'GET', $config['postmanAPIHost'], '/Index/' . $modelName . '/read?id=1'),
                        self::generatePostmanRequest('新增' . $modelName, 'POST', $config['postmanAPIHost'], '/Index/' . $modelName . '/save', $defaultFields),
                        self::generatePostmanRequest('编辑' . $modelName, 'POST', $config['postmanAPIHost'], '/Index/' . $modelName . '/update', array_merge($defaultFields, ['id' => 1])),
                        self::generatePostmanRequest('删除' . $modelName, 'POST', $config['postmanAPIHost'], '/Index/' . $modelName . '/delete', ['id' => [1]]),
                    ],
                ];
            }

            // 统计搜索字段
            if (in_array('s', $config['type'])) {
                $searchFieldMapAll[$modelName] = $searchFieldMap;
            }

            $generateResult['table'] = $tableName;
            $generatedList[] = $generateResult;
        }

        $resultTable = [];

        // 生成 postman API 文件
        if (in_array('p', $config['type'])) {
            $resultTable[0][] = 'Postman';
            $path = Env::get('app_path') . "{$config['projectName']}.postman_collection.json";
            if (is_file($path) && !$config['force']) {
                $tip = 'File exists';
            } else {
                if ($config['dryRun']) {
                    if ($output->isVerbose()){
                        self::writeBlock($output, [
                            "Postman API JSON:",
                            json_encode($postmanAPI, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ]);
                    }
                    $tip = 'Will generate';
                } else {
                    file_put_contents($path, json_encode($postmanAPI, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $tip = 'Generated';
                }
            }
            $resultTable[1][] = $tip;
        }

        // 生成搜索器字段
        if (in_array('s', $config['type'])) {
            $resultTable[0][] = 'SearchFields';
            $path = Env::get('app_path') . "{$config['projectName']}.searchFields.json";
            if (is_file($path) && !$config['force']) {
                $tip = 'File exists';
            } else {
                if ($config['dryRun']) {
                    if ($output->isVerbose()){
                        self::writeBlock($output, [
                            "SearchFields JSON:",
                            json_encode($searchFieldMapAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ]);
                    }
                    $tip = 'Will generate';
                } else {
                    file_put_contents($path, json_encode($searchFieldMapAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $tip = 'Generated';
                }
            }
            $resultTable[1][] = $tip;
        }

        $typeLang = [
            // 'm' => '模型',
            // 'v' => '校验器',
            // 'c' => '控制器',
            // 'p' => 'Postman接口',
            // 's' => '搜索器',
            // 'd' => '模型注释',
            'm' => 'Model',
            'v' => 'Validate',
            'c' => 'Controller',
            // 'p' => 'Postman',
            // 's' => 'SearchFields',
            'd' => 'ModelDoc',
        ];

        $header = ['Table'];
        foreach ($config['type'] as $type) {
            if (isset($typeLang[$type])) {
                $header[] = $typeLang[$type];
            }
        }

        $rows = [];
        foreach ($generatedList as $one) {
            $row = [$one['table']];
            foreach ($config['type'] as $type) {
                if (isset($typeLang[$type])) {
                    $row[] = $one[$type] ?? '';
                }
            }
            $rows[] = $row;
        }

        $table = new Table();
        $table->setHeader($header);
        $table->setRows($rows);
        $this->table($table);

        if ($resultTable) {
            $table2 = new Table();
            $table2->setHeader($resultTable[0]);
            $table2->setRows(array_slice($resultTable, 1));
            $this->table($table2);
        }
    }
}
