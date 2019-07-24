# thinkphp-generator

命令行自动生成数据表模型、校验器、控制器等

## 框架要求

ThinkPHP5.1+

## 安装

~~~ bash
composer require hectorqin/thinkphp-generator
~~~

## 配置

修改项目根目录下config/generator.php中对应的参数

## 使用

~~~ bash
# 帮助
$ php think generate --help
Usage:
  generate [options]

Options:
  -c, --config[=CONFIG]              配置名称，默认为 generator [default: "generator"]
  -t, --table[=TABLE]                要生成的table，多个用,隔开, 默认为所有table
  -p, --tablePrefix[=TABLEPREFIX]    table前缀，多个用,隔开
  -i, --ignoreFields[=IGNOREFIELDS]  忽略的字段，不生成搜索器
  -e, --except[=EXCEPT]              要排除的table，多个用,隔开
      --type[=TYPE]                  要生成的类型，多个用,隔开,如 m,v,c,p,s,d
                                            m -- model, v -- validate, c -- controller, p -- postmanJson, s -- searchAttr, d -- model doc
      --templateDir[=TEMPLATEDIR]    自定义模板文件夹路径，必须有 model.tpl,
                                     controller.tpl, validate.tpl等文件，使用tp模板语法
      --mModule[=MMODULE]            模型模块名
      --vModule[=VMODULE]            校验器模块名
      --cModule[=CMODULE]            控制器模块名
      --mLayer[=MLAYER]              模型分层
      --vLayer[=VLAYER]              校验器分层
      --cLayer[=CLAYER]              控制器分层
      --mBase[=MBASE]                模型继承类，如 app\common\model\EventModel
      --vBase[=VBASE]                校验器继承
      --cBase[=CBASE]                控制器继承类
      --db[=DB]                      数据库配置文件名
      --dryRun[=DRYRUN]              只执行，不保存 [default: false]
  -f, --force[=FORCE]                覆盖已存在文件 [default: false]
      --pName[=PNAME]                PostMan 项目名称，默认使用 数据库名
      --pHost[=PHOST]                PostMan API请求前缀，默认使用 api_prefix 环境变量
  -h, --help                         Display this help message
  -V, --version                      Display this console version
  -q, --quiet                        Do not output any message
      --ansi                         Force ANSI output
      --no-ansi                      Disable ANSI output
  -n, --no-interaction               Do not ask any interactive question
  -v|vv|vvv, --verbose               Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

# 生成 user 表模型、校验器、控制器
php think generate -t user --type=m,v,c

# 生成数据库全部数据表的模型、校验器、控制器
php think generate --type=m,v,c

# 不生成，预览操作
php think generate --type=m,v,c -d

~~~

## License

Apache-2.0
