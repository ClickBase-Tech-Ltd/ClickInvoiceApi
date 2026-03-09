<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

class Serve extends BaseServeCommand
{
    /**
     * Handle the command.
     * If the user did not provide a --port option, set it to 8002.
     */
    public function handle()
    {
        if ($this->input && ! $this->input->hasParameterOption('--port')) {
            $this->input->setOption('port', 8002);
        }

        return parent::handle();
    }
}
