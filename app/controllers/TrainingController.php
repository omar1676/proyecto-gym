<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/TrainingMediaStorage.php';
require_once __DIR__ . '/../services/TrainingService.php';

final class TrainingController
{
    private TenantContext $tenant;

    public function __construct()
    {
        Sesion::iniciar();
        $this->tenant=TenantContext::desdeSesion();
    }

    public function library(): void
    {
        $this->requirePermission('training.view');
        $service=$this->service();
        $page=$this->positiveQuery('page',1);
        try{$exercises=$service->listExercises((string)($_GET['q']??''),(string)($_GET['discipline']??''),$page,24);}
        catch(InvalidArgumentException $e){$exercises=$service->listExercises('', '',1,24);$viewError=$e->getMessage();}
        $selected=null;
        $selectedId=$this->positiveQuery('exercise',0);
        if($selectedId>0)$selected=$service->exercise($selectedId);
        $canManage=Authorization::can($this->tenant->rol(),'training.manage');
        $pageTitle='Entrenamientos · Biblioteca';$paginaActiva='training';$trainingTab='library';
        require __DIR__.'/../views/admin/training_library.php';
    }

    public function createExercise(): void
    {
        $this->requirePost('training.manage');
        try{$id=$this->service()->createExercise($_POST);$this->redirect('training_library',['ok'=>'Ejercicio creado.','exercise'=>$id]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_library',['err'=>$e->getMessage()]);}
        catch(Throwable $e){AppLogger::error('training_exercise_create_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_library',['err'=>'No se pudo crear el ejercicio.']);}
    }

    public function cloneExercise(): void
    {
        $this->requirePost('training.manage');
        try{$id=$this->service()->cloneExercise((int)($_POST['exercise_id']??0),(string)($_POST['name']??''));$this->redirect('training_library',['ok'=>'Ejercicio copiado al catálogo privado.','exercise'=>$id]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_library',['err'=>$e->getMessage()]);}
        catch(Throwable $e){AppLogger::error('training_exercise_clone_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_library',['err'=>'No se pudo copiar el ejercicio.']);}
    }

    public function addMedia(): void
    {
        $this->requirePost('training.manage');
        $exercise=(int)($_POST['exercise_id']??0);
        try{
            if(($_POST['media_type']??'IMAGE')==='VIDEO_REFERENCE')$this->service()->addVideoReference($exercise,(string)($_POST['external_url']??''),$_POST);
            else $this->service()->addImageMedia($exercise,$_FILES['media']??[], $_POST);
            $this->redirect('training_library',['ok'=>'Material visual añadido.','exercise'=>$exercise]);
        }catch(InvalidArgumentException|DomainException $e){$this->redirect('training_library',['err'=>$e->getMessage(),'exercise'=>$exercise]);}
        catch(Throwable $e){AppLogger::error('training_media_create_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_library',['err'=>'No se pudo guardar el material visual.','exercise'=>$exercise]);}
    }

    public function media(): void
    {
        $this->requirePermission('training.view');
        $row=$this->service()->media($this->positiveQuery('id',0));
        if(!$row||$row['media_type']!=='IMAGE'||empty($row['storage_key']))$this->deny(404);
        $file=TrainingMediaStorage::resolve((string)$row['storage_key']);
        if(!$file)$this->deny(404);
        header('Content-Type: '.$file['mime_type']);header('Content-Length: '.(string)$file['size_bytes']);
        header('Content-Disposition: inline; filename="training-image.'.pathinfo((string)$row['storage_key'],PATHINFO_EXTENSION).'"');
        header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
        readfile($file['path']);exit;
    }

    public function templates(): void
    {
        $this->requirePermission('training.view');$service=$this->service();$templates=$service->listTemplates();
        $selected=null;$id=$this->positiveQuery('template',0);if($id>0)$selected=$service->template($id);
        $library=$service->listExercises('', '',1,50)['items'];$canManage=Authorization::can($this->tenant->rol(),'training.manage');
        $pageTitle='Entrenamientos · Plantillas';$paginaActiva='training';$trainingTab='templates';
        require __DIR__.'/../views/admin/training_templates.php';
    }

    public function createTemplate(): void
    {
        $this->requirePost('training.manage');
        try{$id=$this->service()->createTemplate($_POST);$this->redirect('training_templates',['ok'=>'Plantilla creada.','template'=>$id]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_templates',['err'=>$e->getMessage()]);}
        catch(Throwable $e){AppLogger::error('training_template_create_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_templates',['err'=>'No se pudo crear la plantilla.']);}
    }

    public function addTemplateDay(): void
    {
        $this->templateWrite('day',fn(TrainingService $s,int $template):int=>$s->addTemplateDay($template,$_POST));
    }

    public function addTemplateBlock(): void
    {
        $this->templateWrite('block',fn(TrainingService $s,int $template):int=>$s->addTemplateBlock($template,(int)($_POST['day_id']??0),$_POST));
    }

    public function addTemplateExercise(): void
    {
        $this->templateWrite('exercise',fn(TrainingService $s,int $template):int=>$s->addTemplateExercise($template,(int)($_POST['block_id']??0),$_POST));
    }

    public function plans(): void
    {
        $this->requirePermission('training.view');$service=$this->service();$plans=$service->listPlans();
        $selected=null;$id=$this->positiveQuery('plan',0);if($id>0)$selected=$service->plan($id);
        $templates=$service->listTemplates();$members=$service->listMembers();$library=$service->listExercises('','',1,50)['items'];$canManage=Authorization::can($this->tenant->rol(),'training.manage');
        $pageTitle='Entrenamientos · Planes';$paginaActiva='training';$trainingTab='plans';
        require __DIR__.'/../views/admin/training_plans.php';
    }

    public function createPlan(): void
    {
        $this->requirePost('training.manage');
        try{
            $template=(int)($_POST['template_id']??0);$member=(int)($_POST['member_id']??0);
            $id=$template>0?$this->service()->createPlanFromTemplate($template,$member,$_POST):$this->service()->createBlankPlan($_POST);
            $this->redirect('training_plans',['ok'=>'Plan creado como copia independiente.','plan'=>$id]);
        }catch(InvalidArgumentException|DomainException $e){$this->redirect('training_plans',['err'=>$e->getMessage()]);}
        catch(Throwable $e){AppLogger::error('training_plan_create_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_plans',['err'=>'No se pudo crear el plan.']);}
    }

    public function assignPlan(): void
    {
        $this->requirePost('training.manage');$plan=(int)($_POST['plan_id']??0);
        try{$this->service()->assignPlan($plan,(string)($_POST['idempotency_key']??''));$this->redirect('training_plans',['ok'=>'Plan asignado como principal.','plan'=>$plan]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_plans',['err'=>$e->getMessage(),'plan'=>$plan]);}
        catch(Throwable $e){AppLogger::error('training_plan_assign_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_plans',['err'=>'No se pudo asignar el plan.','plan'=>$plan]);}
    }

    public function addPlanDay(): void
    {
        $this->planWrite('day',fn(TrainingService $s,int $plan):int=>$s->addPlanDay($plan,(int)($_POST['plan_version']??0),$_POST));
    }

    public function addPlanBlock(): void
    {
        $this->planWrite('block',fn(TrainingService $s,int $plan):int=>$s->addPlanBlock($plan,(int)($_POST['day_id']??0),(int)($_POST['plan_version']??0),$_POST));
    }

    public function addPlanExercise(): void
    {
        $this->planWrite('exercise',fn(TrainingService $s,int $plan):int=>$s->addPlanExercise($plan,(int)($_POST['block_id']??0),(int)($_POST['plan_version']??0),$_POST));
    }

    public function updatePlanExercise(): void
    {
        $this->requirePost('training.manage');$plan=(int)($_POST['plan_id']??0);
        try{$this->service()->updatePlanExercise((int)($_POST['item_id']??0),(int)($_POST['item_version']??0),$_POST);$this->redirect('training_plans',['ok'=>'Parámetros actualizados.','plan'=>$plan]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_plans',['err'=>$e->getMessage(),'plan'=>$plan]);}
        catch(Throwable $e){AppLogger::error('training_plan_exercise_update_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_plans',['err'=>'No se pudo actualizar el ejercicio.','plan'=>$plan]);}
    }

    private function templateWrite(string $kind,callable $operation): void
    {
        $this->requirePost('training.manage');$template=(int)($_POST['template_id']??0);
        try{$operation($this->service(),$template);$this->redirect('training_templates',['ok'=>'Plantilla actualizada.','template'=>$template]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_templates',['err'=>$e->getMessage(),'template'=>$template]);}
        catch(Throwable $e){AppLogger::error('training_template_'.$kind.'_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_templates',['err'=>'No se pudo actualizar la plantilla.','template'=>$template]);}
    }

    private function planWrite(string $kind,callable $operation): void
    {
        $this->requirePost('training.manage');$plan=(int)($_POST['plan_id']??0);
        try{$operation($this->service(),$plan);$this->redirect('training_plans',['ok'=>'Plan actualizado.','plan'=>$plan]);}
        catch(InvalidArgumentException|DomainException $e){$this->redirect('training_plans',['err'=>$e->getMessage(),'plan'=>$plan]);}
        catch(Throwable $e){AppLogger::error('training_plan_'.$kind.'_failed',['company_id'=>$this->tenant->empresaId()]);$this->redirect('training_plans',['err'=>'No se pudo actualizar el plan.','plan'=>$plan]);}
    }

    private function service(): TrainingService
    {
        return new TrainingService(Database::getInstance()->getConnection(),(int)$this->tenant->empresaId(),$this->tenant->sedeId(),$this->tenant->rol(),$this->tenant->usuarioId());
    }

    private function requirePost(string $permission): void
    {
        $this->requirePermission($permission);
        if($_SERVER['REQUEST_METHOD']!=='POST'||!Csrf::validarPost())$this->deny(403);
    }

    private function requirePermission(string $permission): void
    {
        if(!$this->tenant->autenticado()){header('Location: '.APP_URL.'/index.php?action=login');exit;}
        if($this->tenant->empresaId()===null||!Authorization::can($this->tenant->rol(),$permission)){
            AppLogger::write('SECURITY','training_authorization_denied',['user_id'=>$this->tenant->usuarioId(),'company_id'=>$this->tenant->empresaId(),'site_id'=>$this->tenant->sedeId(),'permission'=>$permission]);
            $this->deny(403);
        }
    }

    private function positiveQuery(string $name,int $default): int
    {
        $raw=(string)($_GET[$name]??'');return ctype_digit($raw)&&$raw!=='0'?(int)$raw:$default;
    }

    private function redirect(string $action,array $params=[]): never
    {
        $url=APP_URL.'/index.php?action='.$action;if($params!==[])$url.='&'.http_build_query($params);header('Location: '.$url);exit;
    }

    private function deny(int $status): never
    {
        http_response_code($status);header('Cache-Control: no-store');echo $status===404?'Recurso no encontrado.':'No tienes permiso para realizar esta operación.';exit;
    }
}
