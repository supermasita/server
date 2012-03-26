<?php
/**
 * A helper class to access service actions, action params and does the real invocation.
 *
 */
class KalturaServiceReflector
{
	/**
	 * @var string
	 */
	private $_serviceId = null;
	
	/**
	 * @var string
	 */
	private $_serviceClass = null;
	
	/**
	 * @var array
	 */
	private $_servicesMap = null;
	
	/**
	 * @var array
	 */
	private $_actions = array();
	
	/**
	 * @var KalturaDocCommentParser
	 */
	private $_docCommentParser = null;
	
	/**
	 * @var KalturaBaseService
	 */
	private $_serviceInstance = null;
	
	/**
	 * @var array
	 */
	private $_reservedKeys = array("service", "action", "format", "ks", "callback");
	
	/**
	 * @var string
	 */
	private $_subService;
	
	/**
	 * @var string
	 */
	private $_pluginName;
	
	/**
	 * @param string $service
	 */
	public function __construct($service, &$action=null)
	{
		$this->_serviceId = strtolower($service);
		$this->_servicesMap = KalturaServicesMap::getMap();
		
		if (!$this->isServiceExists($this->_serviceId))
			throw new Exception("Service [$service] does not exists");
			
		$this->_serviceClass = $this->_servicesMap[$this->_serviceId];
		
		if (!class_exists($this->_serviceClass))
			throw new Exception("Service class [$this->_serviceClass] for service [$service] does not exists");
		
		//If $action was passed, try to find it in $this->_serviceClass
		
		if ($action)
		{
		    $tempReflector = new KalturaServiceReflector($this->_serviceClass);
		    
		    if (!$tempReflector->isActionExists($action))
		    {
        		//If the action $action could not be found on $this->_serviceClass, search for it among the plugin services
		        list ($extendingServiceId, $action) = $this->getExtendingPlugin($service, $action);
		        
		        $this->_serviceClass = $this->_servicesMap[$extendingServiceId];
		    }
		}
		
		
		//If the action could not be found on the plugins either, throw an exception
		$reflectionClass = new ReflectionClass($this->_serviceClass);
			
		$this->_docCommentParser = new KalturaDocCommentParser($reflectionClass->getDocComment()); 
	}
	
	protected function getExtendingPlugin ($service, $action)
	{
	    //if found more than 1 extending service class - throw exception!
	    
	    $extendingPlugins = KalturaPluginManager::getPluginInstances("IKalturaServiceExtender");
	    
	    if (!isset($extendingPlugins))
	    {
	        //throw exception - no extending plugin found among the plugins at all
	    }
	    
	    $foundExtendingService = false;
	    $extendingServiceAndAction = array();
	    foreach ($extendingPlugins as $extendingPlugin)
	    {
	        /* @var $extendingPlugin IKalturaActionsPlugin*/
	       $serviceAndAction = $extendingPlugin->getExtendedActionAndService($service, $action);
	       
	       if ($serviceAndAction && count($serviceAndAction) && !$foundExtendingService)
	       {
	           $foundExtendingService = true;
	           $extendingServiceAndAction = $serviceAndAction;
	       }
	       else if ($serviceAndAction && count($serviceAndAction) && $foundExtendingService)
	       {
	           //throw exception - more than 1 extending service
	       }
	    }
	    
	    if  ($foundExtendingService)
	    {
	        return $extendingServiceAndAction;
	    }
	    
	    return null;
	}
	
	public function getServiceInfo()
	{
	    return $this->_docCommentParser;
	}
	
    public function getServiceId()
	{
		return $this->_serviceId;
	}
	
	public function getPackage()
	{
		return $this->_docCommentParser->package;
	}
	
	
	public function getPluginName()
	{
		if(!is_string($this->_docCommentParser->package) || !strlen($this->_docCommentParser->package))
			return null;
			
		$packages = explode('.', $this->_docCommentParser->package, 2);
		if(count($packages) != 2 || $packages[0] != 'plugins')
			return null;
			
		return $packages[1];
	}
	
	public function isDeprecated()
	{
		return $this->_docCommentParser->deprecated;
	}
	
	public function isServerOnly()
	{
		return $this->_docCommentParser->serverOnly;
	}
	
	public function getServiceName()
	{
		return $this->_docCommentParser->serviceName;
	}
	
	public function getServiceDescription()
	{
		return $this->_docCommentParser->description;
	}
	
	public function isFromPlugin()
	{
		return (strpos($this->_serviceId, '_') > 0);
	}
	
	public function isServiceExists($serviceId)
	{
		if(array_key_exists($serviceId, $this->_servicesMap))
			return true;
			
		if(strpos($serviceId, '_') <= 0)
			return false;

		$serviceId = strtolower($serviceId);
		list($servicePlugin, $serviceName) = explode('_', $serviceId);
		
		$pluginInstances = KalturaPluginManager::getPluginInstances('IKalturaServices');
		if(!isset($pluginInstances[$servicePlugin]))
			return false;
			
		$pluginInstance = $pluginInstances[$servicePlugin];
		$servicesMap = $pluginInstance->getServicesMap();
		KalturaLog::debug(print_r($servicesMap, true));
		foreach($servicesMap as $name => $class)
		{
			if(strtolower($name) == $serviceName)
			{
				$class = $servicesMap[$name];
				KalturaServicesMap::addService($serviceId, $class);
				$this->_servicesMap = KalturaServicesMap::getMap();
				return true;
			}
		}
			
		return false;
	}
	
	public function isActionExists($actionName)
	{
		$actions = $this->getActions();
		$actionId = strtolower($actionName);
		return array_key_exists($actionId, $actions);
	}
	
	public function getActionMethodName($actionId)
	{
		$actions = $this->getActions(false);
		if(isset($actions[$actionId]))
			return $actions[$actionId];
			
		return null;
	}
	
	/**
	 * @param bool $ignoreDeprecated
	 * @return array
	 */
	public function getActions($ignoreDeprecated = false, $ignoreParentClassActions = false)
	{
		$actionsArrayType = intval($ignoreDeprecated);
		if (isset($this->_actions[$actionsArrayType]) && is_array($this->_actions[$actionsArrayType]))
			return $this->_actions[$actionsArrayType];
		
		$reflectionClass = new ReflectionClass($this->_serviceClass);
		
		$reflectionMethods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
		
		$actions = array();
		foreach($reflectionMethods as $reflectionMethod)
		{
		    /* @var $reflectionMethod ReflectionMethod */
			$docComment = $reflectionMethod->getDocComment();
			$parsedDocComment = new KalturaDocCommentParser( $docComment );
			if ($parsedDocComment->action)
			{
				if($ignoreDeprecated && $parsedDocComment->deprecated)
					continue;
				if ($ignoreParentClassActions)
				{
				    KalturaLog::debug("Ignoring parent class actions for class [".$this->getServiceClass()."], parent class [". $reflectionMethod->class."].");
				    if ($reflectionMethod->class != $this->getServiceClass())
				    {
				        continue;
				    }
				}	
				$actionName = $parsedDocComment->action;
				$actionId = strtolower($actionName);
				$actions[$actionId] = $reflectionMethod->getName(); // key is the action id (action name lower cased), value is the method name
			}
		}
		//TODO: get all extending services' actions.
		
		$this->_actions[$actionsArrayType] = $actions;
		
		return $this->_actions[$actionsArrayType];
	}
	
	/**
	 * Function returns all extending actions for the reflected service
	 * @return array
	 */
	public function getExtendingPluginList ()
	{
	    $extendingPlugins = KalturaPluginManager::getPluginInstances("IKalturaServiceExtender");
	    
	    $serviceExtendingPlugins = array();
	    foreach ($extendingPlugins as $extendingPlugin)
	    {
	        $extendingServices = $extendingPlugin->getExtendingServices($this->getServiceName());
	        if (isset($extendingServices) && count($extendingServices) )
	        {
	            $serviceExtendingPlugins[] = $extendingPlugin;
	        }
	    }
	     
	    return $serviceExtendingPlugins;
	}
	
	/**
	 * @param string $actionName
	 * @return KalturaDocCommentParser
	 */
	public function getActionInfo($actionName)
	{
		if (!$this->isActionExists($actionName))
			throw new Exception("Action [$actionName] does not exists for service [$this->_serviceId]");
		
		$actionId = strtolower($actionName);
		$methodName = $this->getActionMethodName($actionId);
		// reflect the service 
		$reflectionClass = new ReflectionClass($this->_serviceClass);
		$reflectionMethod = $reflectionClass->getMethod($methodName);
		
		$docComment = $reflectionMethod->getDocComment();
		$parsedDocComment = new KalturaDocCommentParser( $docComment );
		return $parsedDocComment;
	}
	
	public function getActionParams($actionName)
	{
		if (!$this->isActionExists($actionName))
			throw new Exception("Action [$actionName] does not exists for service [$this->_serviceId]");
			
		$actionId = strtolower($actionName);
		$methodName = $this->getActionMethodName($actionId);
		
		// reflect the service 
		$reflectionClass = new ReflectionClass($this->_serviceClass);
		$reflectionMethod = $reflectionClass->getMethod($methodName);
		
		$docComment = $reflectionMethod->getDocComment();
		$reflectionParams = $reflectionMethod->getParameters();
		$actionParams = array();
		foreach($reflectionParams as $reflectionParam)
		{
			$name = $reflectionParam->getName();
			if (in_array($name, $this->_reservedKeys))
				throw new Exception("Param [$name] in action [$actionName] is a reserved key");
				
			$parsedDocComment = new KalturaDocCommentParser( $docComment, array(
				KalturaDocCommentParser::DOCCOMMENT_REPLACENET_PARAM_NAME => $name , ) );
			$paramClass = $reflectionParam->getClass(); // type hinting for objects  
			if ($paramClass)
			{
				$type = $paramClass->getName();
			}
			else //
			{
				$result = null;
				if ($parsedDocComment->param)
					$type = $parsedDocComment->param;
				else 
				{
					throw new Exception("Type not found in doc comment for param [".$name."] in action [".$actionName."] in service [".$this->_serviceId."]");
				}
			}
			
			$paramInfo = new KalturaParamInfo($type, $name);
			$paramInfo->setDescription($parsedDocComment->paramDescription);
			
			if ($reflectionParam->isOptional()) // for normal parameters
			{
				$paramInfo->setDefaultValue($reflectionParam->getDefaultValue());
				$paramInfo->setOptional(true);
			}
			else if ($reflectionParam->getClass() && $reflectionParam->allowsNull()) // for object parameter
			{
				$paramInfo->setOptional(true);
			}
			
			$actionParams[$name] = $paramInfo;
		}
		
		return $actionParams;
	}
	
	/**
	 * @param unknown_type $actionName
	 * @return KalturaParamInfo
	 */
	public function getActionOutputType($actionName)
	{
		if (!$this->isActionExists($actionName))
			throw new Exception("Action [$actionName] does not exists for service [$this->_serviceId]");

		$actionId = strtolower($actionName);
		$methodName = $this->getActionMethodName($actionId);
		
		// reflect the service
		$reflectionClass = new ReflectionClass($this->_serviceClass);
		$reflectionMethod = $reflectionClass->getMethod($methodName);
		
		$docComment = $reflectionMethod->getDocComment();
		$parsedDocComment = new KalturaDocCommentParser($docComment);
		if ($parsedDocComment->returnType)
			return new KalturaParamInfo($parsedDocComment->returnType, "output");
		
		return null;
	}
	
	public function invoke($actionName, $arguments)
	{
	    $actionId = strtolower($actionName);
		$methodName = $this->getActionMethodName($actionId);
		$instance = $this->getServiceInstance();
		return call_user_func_array(array($instance, $methodName), $arguments);
	}
	
	public function getServiceInstance()
	{
		if ( ! $this->_serviceInstance ) 
		{
			 $this->_serviceInstance = new $this->_serviceClass();
		}
		
		return $this->_serviceInstance;
	}
	
	public function getServiceClass()
	{
		return $this->_serviceClass;
	}
	
	public function removeAction($actionName)
	{
		$actionId = strtolower($actionName);
		if(isset($this->_actions[0]) && isset($this->_actions[0][$actionId]))
			unset($this->_actions[0][$actionId]);
		if(isset($this->_actions[1]) && isset($this->_actions[1][$actionId]))
			unset($this->_actions[1][$actionId]);
	}
}