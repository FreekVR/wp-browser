<?php


use Behat\Gherkin\Node\TableNode;
use Codeception\Configuration;
use Codeception\Scenario;
use Codeception\Step\Action;
use Codeception\Util\Template;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use tad\WPBrowser\Command\Steppify;

class SteppifyTest extends \Codeception\Test\Unit
{
    /**
     * @var string
     */
    protected $targetContextFile;

    /**
     * @var string
     */
    protected $targetSuiteConfigFile;

    /**
     * @var string
     */
    protected $suiteConfigBackup;
    /**
     * @var \IntegrationTester
     */
    protected $tester;

    /**
     * @var array
     */
    protected $targetSuiteConfig = [];

    /**
     * @var FilesystemIterator
     */
    protected $testModules;
    protected $classTemplate = <<< EOF
class {{name}} {

    use {{trait}};

    protected \$scenario;
    
    public function __construct(\$scenario) {
        \$this->scenario = \$scenario;
    }

    protected function getScenario() {
        return \$this->scenario;
    }
}
EOF;


    /**
     * @test
     * it should exist as a command
     */
    public function it_should_exist_as_a_command()
    {
        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest'
        ]);
    }

    /**
     * @param Application $app
     */
    protected function addCommand(Application $app)
    {
        $app->add(new Steppify('steppify'));
    }

    /**
     * @test
     * it should return error message if target suite does not exist
     */
    public function it_should_return_error_message_if_target_suite_does_not_exist()
    {
        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'notexisting'
        ]);

        $this->assertContains("Suite 'notexisting' does not exist", $commandTester->getDisplay());
    }

    /**
     * @test
     * it should generate an helper steps module
     */
    public function it_should_generate_an_helper_steps_module()
    {
        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
        ]);

        $this->assertFileExists($this->targetContextFile);
    }

    /**
     * @test
     * it should generate a trait file
     */
    public function it_should_generate_a_trait_file()
    {
        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
        ]);

        $this->assertFalse(trait_exists('_generated\SteppifytestGherkinSteps', false));
        $this->assertFileExists($this->targetContextFile);

        require_once $this->targetContextFile;

        $this->assertTrue(trait_exists('_generated\SteppifytestGherkinSteps', false));
    }

    /**
     * @test
     * it should not generate any method if the suite Tester class contains no methods
     * @depends it_should_generate_a_trait_file
     */
    public function it_should_not_generate_any_method_if_the_suite_tester_class_contains_no_methods()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleZero']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);
        $methods = $ref->getMethods();

        $this->assertEmpty(array_filter($methods, function (ReflectionMethod $method) {
            // exclude utility methods
            return !preg_match('/^_/', $method->name);
        }));
    }

    protected function backupSuiteConfig()
    {
        copy($this->targetSuiteConfigFile, $this->suiteConfigBackup);
        $this->targetSuiteConfig = Yaml::parse(file_get_contents($this->targetSuiteConfigFile));
    }

    protected function setSuiteModules(array $modules)
    {
        $shortModules = array_map(function ($module) {
            $frags = explode('\\', $module);
            return end($frags);
        }, $modules);

        foreach ($this->testModules as $testModule) {
            if (in_array(basename($testModule, '.php'), $shortModules)) {
                require_once $testModule;
            }
        }

        $this->targetSuiteConfig['modules']['enabled'] = $modules;
        file_put_contents($this->targetSuiteConfigFile, Yaml::dump($this->targetSuiteConfig));
    }

    /**
     * @test
     * it should generate given, when and then step for method by default
     */
    public function it_should_generate_given_when_and_then_step_for_method_by_default()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomething'));

        $reflectedMethod = $ref->getMethod('step_doSomething');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Given /I do something/', $methodDockBlock);
        $this->assertContains('@When /I do something/', $methodDockBlock);
        $this->assertContains('@Then /I do something/', $methodDockBlock);
    }

    /**
     * @test
     * it should generate given step only if specified
     */
    public function it_should_generate_given_step_only_if_specified()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingTwo'));

        $reflectedMethod = $ref->getMethod('step_doSomethingTwo');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Given /I do something two/', $methodDockBlock);
        $this->assertNotContains('@When /I do something two/', $methodDockBlock);
        $this->assertNotContains('@Then /I do something two/', $methodDockBlock);
    }

    /**
     * @test
     * it should generate when step only if specified
     */
    public function it_should_generate_when_step_only_if_specified()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingThree'));

        $reflectedMethod = $ref->getMethod('step_doSomethingThree');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertNotContains('@Given /I do something three/', $methodDockBlock);
        $this->assertContains('@When /I do something three/', $methodDockBlock);
        $this->assertNotContains('@Then /I do something three/', $methodDockBlock);
    }

    /**
     * @test
     * it should generate then step only if specified
     */
    public function it_should_generate_then_step_only_if_specified()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingFour'));

        $reflectedMethod = $ref->getMethod('step_doSomethingFour');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertNotContains('@Given /I do something four/', $methodDockBlock);
        $this->assertNotContains('@When /I do something four/', $methodDockBlock);
        $this->assertContains('@Then /I do something four/', $methodDockBlock);
    }

    /**
     * @test
     * it should pass string arguments directly to original method
     */
    public function it_should_pass_string_arguments_directly_to_original_method()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $trait = '_generated\\' . $class;
        $this->assertTrue(trait_exists($trait));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingWithStringOne'));

        $reflectedMethod = $ref->getMethod('step_doSomethingWithStringOne');

        $parameters = $reflectedMethod->getParameters();

        $this->assertNotEmpty($parameters);
        $this->assertTrue($parameters[0]->name === 'arg1');
    }

    /**
     * @test
     * it should allow defaulting string arguments
     */
    public function it_should_allow_defaulting_string_arguments()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $trait = '_generated\\' . $class;
        $this->assertTrue(trait_exists($trait));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingWithStringTwo'));

        $reflectedMethod = $ref->getMethod('step_doSomethingWithStringTwo');

        $parameters = $reflectedMethod->getParameters();

        $this->assertNotEmpty($parameters);
        $this->assertTrue($parameters[0]->name === 'arg1');
        $this->assertTrue($parameters[0]->isOptional());
        $this->assertTrue($parameters[0]->getDefaultValue() === 'foo');
    }

    /**
     * @test
     * it should allow preventing a method from generating a gherking step marking it with @gherkin no
     */
    public function it_should_allow_preventing_a_method_from_generating_a_gherking_step_marking_it_with_gherkin_no()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $trait = '_generated\\' . $class;
        $this->assertTrue(trait_exists($trait));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertFalse($ref->hasMethod('step_noGherkin'));
    }

    /**
     * @test
     * it should translate array parameters to multiple calls to base method
     */
    public function it_should_translate_array_parameters_to_multiple_calls_to_base_method()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $trait = '_generated\\' . $class;
        $this->assertTrue(trait_exists($trait));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomethingWithArray'));

        $parameters = (new ReflectionMethod($trait, 'step_doSomethingWithArray'))->getParameters();

        /** @var ReflectionParameter $first */
        $first = $parameters[0];
        $this->assertEquals(TableNode::class, $first->getClass()->name);

        $method = $arguments = '';

        $instance = $this->getInstanceForTrait($trait, $id, $method, $arguments);

        $table = new TableNode([
            ['keyOne', 'keyTwo', 'keyThree'],
            ['foo', 'baz', 'bar'],
            [23, 'foo', 'baz'],
        ]);

        $instance->step_doSomethingWithArray($table);

        $this->assertEquals('doSomethingWithArray', $method);
        $expected = json_encode([
            ['keyOne' => 'foo', 'keyTwo' => 'baz', 'keyThree' => 'bar'],
            ['keyOne' => 23, 'keyTwo' => 'foo', 'keyThree' => 'baz'],
        ]);
        $this->assertEquals($expected, $arguments);
    }

    /**
     * @param $trait
     * @param $id
     * @param $method
     * @param $arguments
     */
    protected function getInstanceForTrait($trait, $id, &$method, &$arguments)
    {
        $className = 'ClassUsing_' . $id;
        $classCode = (new Template($this->classTemplate))
            ->place('name', $className)
            ->place('trait', $trait)
            ->produce();

        eval($classCode);

        /** @var Scenario $scenario */
        $scenario = $this->prophesize(Scenario::class);
        $scenario->runStep(Prophecy\Argument::type(Action::class))->will(function (array $args) use (
            &$method,
            &
            $arguments
        ) {
            $action = $args[0];
            $method = $action->getAction();
            $arguments = $action->getArgumentsAsString();
        });

        return $instance = new $className($scenario->reveal());
    }

    /**
     * @test
     * it should create steps from more than one module
     */
    public function it_should_create_steps_from_more_than_one_module()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleOne', '\tad\WPBrowser\Tests\ModuleTwo']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_doSomething'));
        $this->assertTrue($ref->hasMethod('step_seeSomething'));
    }

    /**
     * @test
     * it should add placeholders for methods in doc
     */
    public function it_should_add_placeholders_for_methods_in_doc()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleTwo']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_seeElement'));

        $reflectedMethod = $ref->getMethod('step_seeElement');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Then /I see element :name/', $methodDockBlock);
    }

    /**
     * @test
     * it should join multiple arguments with and
     */
    public function it_should_join_multiple_arguments_with_and()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleTwo']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_seeElementWithColor'));

        $reflectedMethod = $ref->getMethod('step_seeElementWithColor');
        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Then /I see element :name with color :color/', $methodDockBlock);
    }

    /**
     * @test
     * it should mark optional parameters as optional
     */
    public function it_should_mark_optional_parameters_as_optional()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleTwo']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_seeElementInContext'));

        $reflectedMethod = $ref->getMethod('step_seeElementInContext');

        $parameters = $reflectedMethod->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertNull($parameters[1]->getDefaultValue());

        $methodDockBlock = $reflectedMethod->getDocComment();

        // public function seeElementInContext($context, $text = null)

        $this->assertContains('@Then /I see element in context :context/', $methodDockBlock);
        $this->assertContains('@Then /I see element in context :context and text :text/', $methodDockBlock);
    }

    /**
     * @test
     * it should replace words with parameter names when found
     */
    public function it_should_replace_words_with_parameter_names_when_found()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleTwo']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_haveElementWithColorAndSize'));

        $reflectedMethod = $ref->getMethod('step_haveElementWithColorAndSize');

        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Given /I  have element :element with color :color and size :size/', $methodDockBlock);
    }

    /**
     * @test
     * it should support complex methods name and optional arguments
     */
    public function it_should_support_complex_methods_name_and_optional_arguments()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleThree']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_haveShapeWithColorAndSize'));

        $reflectedMethod = $ref->getMethod('step_haveShapeWithColorAndSize');

        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Given /I  have shape :shape/', $methodDockBlock);
        $this->assertContains('@Given /I  have shape :shape with color :color/', $methodDockBlock);
        $this->assertContains('@Given /I  have shape :shape with color :color and size :size/', $methodDockBlock);
    }

    /**
     * @test
     * it should accept a gherkin steps generation configuration file
     */
    public function it_should_accept_a_gherkin_steps_generation_configuration_file()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleFour']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id,
            '--steps-config' => codecept_data_dir('steppify/configs/module-4-1.yml')
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_haveElementInDatabase'));

        $reflectedMethod = $ref->getMethod('step_haveElementInDatabase');

        $methodDockBlock = $reflectedMethod->getDocComment();

        $this->assertContains('@Given /I have element :element in database/', $methodDockBlock);
        $this->assertNotContains('@When /I have element :element in database/', $methodDockBlock);
        $this->assertNotContains('@Then /I have element :element in database/', $methodDockBlock);
    }

    /**
     * @test
     * it should allow specifying a module method should be skipped in the configuration file
     */
    public function it_should_allow_specifying_a_module_method_should_be_skipped_in_the_configuration_file()
    {
        $this->backupSuiteConfig();
        $this->setSuiteModules(['\tad\WPBrowser\Tests\ModuleFive']);

        $app = new Application();
        $this->addCommand($app);
        $command = $app->find('steppify');
        $commandTester = new CommandTester($command);

        $id = uniqid();

        $commandTester->execute([
            'command' => $command->getName(),
            'suite' => 'steppifytest',
            '--postfix' => $id,
            '--steps-config' => codecept_data_dir('steppify/configs/module-5-1.yml')
        ]);

        $class = 'SteppifytestGherkinSteps' . $id;

        require_once(Configuration::supportDir() . '_generated/' . $class . '.php');

        $this->assertTrue(trait_exists('_generated\\' . $class));

        $ref = new ReflectionClass('_generated\SteppifytestGherkinSteps' . $id);

        $this->assertTrue($ref->hasMethod('step_methodOne'));
        $this->assertFalse($ref->hasMethod('step_methodTwo'));
    }

    protected function _before()
    {
        $this->targetContextFile = Configuration::supportDir() . '/_generated/SteppifytestGherkinSteps.php';
        $this->targetSuiteConfigFile = codecept_root_dir('tests/steppifytest.suite.dist.yml');
        $this->suiteConfigBackup = codecept_root_dir('tests/steppifytest.suite.dist.yml.backup');

        $this->testModules = new FilesystemIterator(codecept_data_dir('modules'),
            FilesystemIterator::CURRENT_AS_PATHNAME);
    }

    protected function _after()
    {
        $pattern = Configuration::supportDir() . '_generated/SteppifytestGherkinSteps*.php';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }

        if (file_exists($this->suiteConfigBackup)) {
            unlink($this->targetSuiteConfigFile);
            rename($this->suiteConfigBackup, $this->targetSuiteConfigFile);
        }
    }
}
