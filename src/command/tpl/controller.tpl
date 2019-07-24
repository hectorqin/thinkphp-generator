namespace app\{$cModule}\controller{$cLayer|default=''};

use {$cBase};
use app\common\model{$mLayer|default=''}\{$modelName} as {$modelAlias};
use app\common\validate{$vLayer|default=''}\{$modelName} as {$validateAlias};
use app\common\Error;
use think\facade\Log;
use think\Request;
use think\Db;

class {$modelName} extends {$cBaseName}

{
    {if !$baseControllerCorrect}

    const LIST_ALL_DATA = 'list_all';
    {/if}

    /**
     * 列表
     *
     * @param  \think\Request  $request
     * @return \think\Response
     */
    public function index(Request $request)
    {
        $page     = $request->param('page/d', 1);
        if($page < 1){
            return \app\errorJson(Error::ARGS_WRONG, '页码不能小于1');
        }
        $listRows = $request->param('listRows/d', \Config::get('page.list_rows'));
        if($listRows <= 0){
            return \app\errorJson(Error::ARGS_WRONG, '分页大小不能小于0');
        }
        $maxRows = \Config::get('page.list_max_rows', 100);
        if($listRows > $maxRows){
            return \app\errorJson(Error::ARGS_WRONG, '分页大小不能大于' . $maxRows);
        }
        $option   = $request->param('option/s', '');
        if($option){
            {if function_exists('\\app\\getHiddenFields')}

            list($action, $isValid) = \app\checkListOption($option);
            if(!$isValid){
                return \app\errorJson(Error::ARGS_WRONG, 'option参数校验失败');
            }
            {/if}

        }

        $query = {$modelAlias}::withSearch(
            {$modelAlias}::$searchFields,
            $request->param()
        );

        $query->order($request->param('sort',  ''))->with($request->param('with', ''));

        $total = $query->count();

        if(!isset($action) || $action != self::LIST_ALL_DATA){
            $query->page($page, $listRows);
        }

        $hiddenFields = [{$hiddenFieldStr}];
        {if function_exists('\\app\\getHiddenFields')}

        $hiddenFields = \app\getHiddenFields($hiddenFields);
        {/if}

        $list  = $query->hidden($hiddenFields)->select();
        return \app\successJson([
            'list'       => $list,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'listRows' => $listRows,
            ],
        ]);
    }

    /**
     * 新增
     *
     * @param  \think\Request  $request
     * @return \think\Response
     */
    public function save(Request $request)
    {
        $data = $request->only([
            {$fieldStr|raw}
        ]);

        $result = $this->validate(
            $data,
            {$validateAlias}::class
        );

        if (true !== $result) {
            // 验证失败 输出错误信息
            return \app\errorJson(Error::ARGS_WRONG, '数据未通过验证', Error::getValidateMessage($result));
        }

        try {
            ${$modelInstance} = {$modelAlias}::create($data);
            return \app\successJson('保存成功', [
                'info' => ${$modelInstance}

            ]);
        } catch (\Exception $e) {
            return \app\errorJson(Error::getErrorCode($e, Error::DB_WRONG), '保存失败', Error::getErrorMessage($e));
        }
    }

    /**
     * 查看详情
     *
     * @param  int  $id
     * @param  string  $with
     * @param  array  $readBy 查询条件
     * @return \think\Response
     */
    public function read(int $id = 0, string $with='', array $readBy = null)
    {
        if (empty($readBy) && (!$id || (int) $id <= 0)) {
            return \app\errorJson(Error::ARGS_WRONG, '参数错误');
        }
        if ($id) {
            ${$modelInstance} = {$modelAlias}::with($with)->get((int) $id);
        } else {
            ${$modelInstance} = {$modelAlias}::with($with)->withSearch({$modelAlias}::$searchFields, $readBy)->find();
        }
        return ${$modelInstance} ? \app\successJson(${$modelInstance}) : \app\errorJson(Error::DATA_NOT_FOUND, '数据不存在');
    }

    /**
     * 更新
     *
     * @param  \think\Request  $request
     * @param  int  $id
     * @return \think\Response
     */
    public function update(Request $request, int $id = 0)
    {
        if ((!$id || (int) $id <= 0)) {
            return \app\errorJson(Error::ARGS_WRONG, '参数错误');
        }
        ${$modelInstance} = {$modelAlias}::get((int) $id);
        if (!${$modelInstance}) {
            return \app\errorJson(Error::DATA_NOT_FOUND, '数据不存在');
        }
        $data = $request->only([
            {$updateFieldStr|raw}
        ]);

        $result = $this->validate(
            $data,
            {$validateAlias}::class
        );

        if (true !== $result) {
            // 验证失败 输出错误信息
            return \app\errorJson(Error::ARGS_WRONG, '数据未通过验证', Error::getValidateMessage($result));
        }

        ${$modelInstance}->readonly([{$readonlyFieldStr}])->appendData($data, true);
        if (empty(${$modelInstance}->getChangedData())) {
            return \app\successJson('数据未变化');
        }

        try {
            ${$modelInstance}->save();
            return \app\successJson('保存成功', [
                'info' => ${$modelInstance}

            ]);
        } catch (\Exception $e) {
            return \app\errorJson(Error::getErrorCode($e, Error::DB_WRONG), '保存失败', Error::getErrorMessage($e));
        }
    }

    /**
     * 删除
     *
     * @param  int|array  $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (empty($id)) {
            return \app\errorJson(Error::ARGS_WRONG, '参数错误');
        }
        if (!is_array($id)){
            if( (int) $id < 0){
                return \app\errorJson(Error::ARGS_WRONG, '参数错误');
            }
            $id = [ (int)$id ];
        }
        $list = {$modelAlias}::all($id);
        if ($list->isEmpty()) {
            return \app\errorJson(Error::DATA_NOT_FOUND, '数据不存在');
        }
        $success = [];
        $error   = [];
        /** @var {$modelAlias} $item */
        foreach ($list as $item) {
            // 多个删除不互相影响
            $pkID = $item->{$pk};
            try {
                Db::startTrans();
                $item->tryToDelete();
                Db::commit();
                $success[] = $pkID;
            } catch (\Exception $e) {
                Db::rollback();
                $error[] = Error::getErrorMessage($e, [
                    'id' => $pkID
                ]);
            }
        }
        return \app\successJson(count($success) ? '删除成功' : '删除失败', [
            'error'   => $error,
            'success' => $success,
        ]);
    }

}
