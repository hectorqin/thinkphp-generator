<?php
namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;

class AST extends Command
{
    protected function configure()
    {
        $this->setName('AST')
            ->addOption('type', null, Option::VALUE_OPTIONAL, "要解析的类型，多个用,隔开,如 m,c\n m -- model, c -- controller")
            ->addOption('scope', null, Option::VALUE_OPTIONAL, "要解析的范围，多个用,隔开,如 c,m\n c -- const, m -- method")
            ->addOption('dryRun', null, Option::VALUE_OPTIONAL, "只执行，不保存")
            ->setDescription('Print AST of file');
    }

    protected function execute(Input $input, Output $output)
    {
        $typeList  = ['m', 'c'];
        $scopeList = ['c', 'm'];

        $dryRun = false;
        if ($input->hasOption('dryRun')) {
            $dryRun = $input->getOption('dryRun');
        }

        if ($input->hasOption('type')) {
            $typeList = explode(',', $input->getOption('type'));
        }

        if ($input->hasOption('scope')) {
            $scopeList = explode(',', $input->getOption('scope'));
        }

        $date = date("Ymd_Hi");
        if (in_array('m', $typeList)) {
            $this->generateTypeAST("./application/common/model/**.php", $scopeList, $dryRun ? false : Env::get('app_path') . "modelAST-{$date}.json");
        }
        if (in_array('c', $typeList)) {
            $this->generateTypeAST("./application/index/controller/**.php", $scopeList, $dryRun ? false : Env::get('app_path') . "controllerAST-{$date}.json");
        }
    }

    protected function generateTypeAST($globPattern, $scopeList, $savePath)
    {
        $ASTData          = [];
        $generateConstant = in_array('c', $scopeList);
        $generateMethod   = in_array('m', $scopeList);
        foreach (glob($globPattern) as $file) {
            $className = str_replace(['./application', '.php', '/'], ['/app', '', '\\'], $file);
            $class     = new \ReflectionClass($className);
            if ($generateConstant) {
                $constants = $class->getReflectionConstants();

                foreach ($constants as $constant) {
                    if ($constant->class != $class->name) {
                        continue;
                    }
                    if (!in_array($constant->name, ['EVENT_INSERT', 'EVENT_UPDATE', 'EVENT_DELETE'])) {
                        $ASTData[$class->name]['constant'][] = [
                            'name'  => $constant->name,
                            'value' => $constant->getValue(),
                            'doc'   => str_replace(["/**\n", "* ", '*/'], '', $constant->getDocComment()),
                        ];
                    }
                }
            }

            if ($generateMethod) {
                $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    if ($method->class != $class->name) {
                        continue;
                    }
                    $args = [];
                    foreach ($method->getParameters() as $arg) {
                        $args[] = [
                            'name'    => $arg->name,
                            'default' => $arg->isDefaultValueAvailable() ? $arg->getDefaultValue() : '',
                        ];
                    }
                    $ASTData[$class->name]['method'][] = [
                        'name'       => $method->name,
                        '__toString' => $method->__toString(),
                        'args'       => $args,
                        'doc'        => str_replace(["/**\n", "* ", '*/'], '', $method->getDocComment()),
                    ];
                }
            }
        }
        // dump($ASTData);
        if ($savePath) {
            file_put_contents($savePath, json_encode($ASTData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            dump($ASTData);
        }
    }
}
