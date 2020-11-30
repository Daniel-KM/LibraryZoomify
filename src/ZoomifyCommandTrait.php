<?php
namespace DanielKm\Zoomify;

/**
 * Copyright 2014-2020 Daniel Berthereau (Daniel.git@Berthereau.net)
 *
 */
trait ZoomifyCommandTrait
{
    /**
     * Execute a command.
     *
     * Expects arguments to be properly escaped.
     *
     * @see \Omeka\Stdlib\Cli
     *
     * @param string $command An executable command
     * @return string|false The command's standard output or false on error
     */
    protected function execute($command)
    {
        switch ($this->executeStrategy) {
            case 'proc_open':
                $output = $this->procOpen($command);
                break;
            case 'exec':
            default:
                $output = $this->exec($command);
                break;
        }
        return $output;
    }

    /**
     * Execute command using PHP's exec function.
     *
     * @see http://php.net/manual/en/function.exec.php
     * @param string $command
     * @return string|false
     */
    protected function exec($command)
    {
        $output = [];
        $exitCode = null;
        exec($command, $output, $exitCode);
        if (0 !== $exitCode) {
            return false;
        }
        return implode(PHP_EOL, $output);
    }

    /**
     * Execute command using PHP's proc_open function.
     *
     * For servers that allow proc_open. Logs standard error.
     *
     * @see http://php.net/manual/en/function.proc-open.php
     * @param string $command
     * @return string|false
     */
    protected function procOpen($command)
    {
        // Using proc_open() instead of exec() solves a problem where exec('commandx')
        // fails with a "Permission Denied" error because the current working
        // directory cannot be set properly via exec().  Note that exec() works
        // fine when executing in the web environment but fails in CLI.
        $descriptorSpec = [
            0 => ['pipe', 'r'], //STDIN
            1 => ['pipe', 'w'], //STDOUT
            2 => ['pipe', 'w'], //STDERR
        ];
        $pipes = [];
        $proc = proc_open($command, $descriptorSpec, $pipes, getcwd());
        if (!is_resource($proc)) {
            return false;
        }
        $output = stream_get_contents($pipes[1]);
        // $errors = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($proc);
        if (0 !== $exitCode) {
            return false;
        }
        return trim($output);
    }
}
