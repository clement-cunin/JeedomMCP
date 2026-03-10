<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}
$plugin = plugin::byId('JeedomMCP');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType('JeedomMCP');
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Plugin management}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Add}}</span>
            </div>
        </div>
        <?php foreach ($eqLogics as $eqLogic) { ?>
            <div class="eqLogicDisplayCard cursor" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
                <img src="<?php echo $plugin->getPathImgIcon(); ?>" />
                <br>
                <span><?php echo $eqLogic->getHumanName(); ?></span>
            </div>
        <?php } ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <a class="btn btn-default btn-sm eqLogicAction" data-action="configure">
                <i class="fas fa-cogs"></i> {{Advanced configuration}}
            </a>
            <a class="btn btn-default btn-sm eqLogicAction" data-action="copy">
                <i class="fas fa-copy"></i> {{Copy}}
            </a>
            <a class="btn btn-danger btn-sm eqLogicAction" data-action="remove">
                <i class="fas fa-minus-circle"></i> {{Remove}}
            </a>
            <a class="btn btn-success btn-sm eqLogicAction" data-action="save">
                <i class="fas fa-check-circle"></i> {{Save}}
            </a>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Equipment}}
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Name}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Equipment name}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Parent object}}</label>
                            <div class="col-sm-3">
                                <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{None}}</option>
                                    <?php foreach (jeeObject::all() as $object) { ?>
                                        <option value="<?php echo $object->getId(); ?>"><?php echo $object->getName(); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Enabled}}</label>
                            <div class="col-sm-1">
                                <input type="checkbox" class="eqLogicAttr bootstrapSwitch" data-l1key="isEnable" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Visible}}</label>
                            <div class="col-sm-1">
                                <input type="checkbox" class="eqLogicAttr bootstrapSwitch" data-l1key="isVisible" />
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>
</div>
