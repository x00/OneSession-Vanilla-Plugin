<?php if (!defined('APPLICATION')) exit();

// Define the plugin:
$PluginInfo['OneSession'] = array(
   'Name' => 'OneSession',
   'Description' => 'It will force users to sign in if they have been logged in elsewhere, a single browser session at one time (apart from admins, who are immune)',
   'Version' => '0.1.2b',
   'Author' => "Paul Thomas",
   'RequiredApplications' => array('Vanilla' => '2.0.18'),
   'MobileFriendly' => TRUE,
   'AuthorEmail' => 'dt01pqt_pt@yahoo.com',
   'AuthorUrl' => 'http://vanillaforums.org/profile/x00'
);

class OneSession extends Gdn_Plugin {
    
    private $SignIn=FALSE;
    private $KillSession=FALSE;
    
    private function Kill($CookieName){
        setcookie($CookieName,'',strtotime('- 1 month'),Url('/'));
    }
    
    private function Purge(){
        $CookieName = C('Garden.Cookie.Name', 'Vanilla');
        foreach($_COOKIE As $CookieN => $Cookie){
            if(strpos($CookieN,$CookieName.'_o_')===0)
                $this->Kill($CookieN);
        }
    }
    
    private function Set($CookieName,$CookieValue,$MarkAsSet=FALSE){
        if($MarkAsSet && GetValue($CookieName,$CookieValue,$_COOKIE))
            Gdn::SQL()->Update('User')->Set('OneSessionSet',1)->Where('UserID',Gdn::Session()->UserID)->Put();
        setcookie($CookieName,$CookieValue,strtotime(C('Plugins.OneSession.PersistFor','+ 1 month')),Url('/'));
        $_COOKIE[$CookieName]=$CookieValue;
    }
    
    private function Start($Set=TRUE){
        $CookieName = C('Garden.Cookie.Name', 'Vanilla');
        $OneName=dechex(rand(0,255));
        $OneValue=md5(uniqid());
        $this->Purge();
        
        $OneSession = array(
            'OneSessionID'=>$OneName.'|'.$OneValue,
            'OneSessionSet'=>$Set?1:0,
            'OneSessionSetLast'=>Gdn_Format::ToDateTime()
        );
        Gdn::SQL()->Update('User')->Set($OneSession)->Where('UserID',Gdn::Session()->UserID)->Put();
        $this->Set($CookieName.'_o_'.$OneName,$OneValue);
    }
    
    public function EntryController_SignIn_Handler($Sender){
        if ($Sender->Form->IsPostBack()) {
            $this->SignIn=TRUE;
        }
    }
    
    public function Base_AfterGetSession_Handler($Sender){
        if(!$this->SignIn || !Gdn::Session()->IsValid() || Gdn::Session()->User->Admin || Gdn::Session()->CheckPermission('Garden.Settings.Manage'))
            return;
        $this->Start(FALSE);
    }
    
    public function Base_BeforeDispatch_Handler($Sender){
        if(C('Plugins.OneSession.Version')!=$this->PluginInfo['Version'])
            $this->Structure();
        $ParsedRequest = Gdn::Request()->Export('Parsed');
        $Path = strtolower($ParsedRequest['Path']);
        if(preg_match('/^entry(\/.*)?$/',$Path))
            return;
            
        $User=Gdn::Session()->User;
        if(!Gdn::Session()->IsValid() || $User->Admin || Gdn::Session()->CheckPermission('Garden.Settings.Manage'))
            return;
        
        $OneSession = $User->OneSessionID;
        $CookieName = C('Garden.Cookie.Name', 'Vanilla');
        
        list($OneName,$OneValue) = split('\|',$OneSession ? $OneSession : '|');
        
        if(!$User->OneSessionSet){
            $this->Purge();
            $this->Set($CookieName.'_o_'.$OneName,$OneValue,TRUE);
        }else if(!$OneSession || (GetValue($CookieName.'_o_'.$OneName,$_COOKIE)!=$OneValue)){
            $this->KillSession=TRUE;
            
        }else{
            if(strtotime(C('Plugins.OneSession.RefreshEvery','+ 60 seconds'),strtotime($User->OneSessionSetLast))<time())
                $this->Start();
                //$this->Set($CookieName.'_o_'.$OneName,$OneValue);
        }

    }
    
    public function Base_BeforeControllerMethod_Handler($Sender,$Args){
        $Controller = $Args['Controller'];
        if($this->KillSession && DELIVERY_METHOD_XHTML==$Controller->DeliveryMethod() && DELIVERY_TYPE_ALL==$Controller->DeliveryType()){
            Gdn::Session()->End();
            $this->Purge();
            Redirect('entry/signin');
        }
    }
    
    public function Setup() {
        $this->Structure();
    }

    public function Structure(){
        Gdn::Structure()
        ->Table('User')
        ->Column('OneSessionID','varchar(35)',null)
        ->Column('OneSessionSet','int(4)',0)
        ->Column('OneSessionSetLast','datetime',null)
        ->Set();
        
        SaveToConfig('Plugins.OneSession.Version', $this->PluginInfo['Version']);
    }
}
