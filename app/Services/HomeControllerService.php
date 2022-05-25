<?php


namespace App\Services;

use App\Events\StartBatchEvent;
use App\Http\Resources\BatchLogsResource;
use App\Http\Resources\JobLogsResource;
use App\Jobs\GuessJob;
use App\Models\Batch;
use App\Models\JobLog;
use App\Models\Param;
use Illuminate\Support\Facades\Bus;

class HomeControllerService implements HomeControllerServiceInterface
{
    public function show($request)
    {
        if ($request->has('transaction')) {
            return JobLogsResource::collection(JobLog::where('transaction', '=', $request->get('transaction'))->get());
        }

        return JobLogsResource::collection(JobLog::all());
    }

    public function start($request)
    {
        $args = [];

        $args['tries'] = $request->tries ?? config('guessjob.tries');
        $args['guessNumber'] = $request->guess_number ?? config('guessjob.guessNumber');
        $args['range'] =
            [
                'start' => $request->range['start'] ?? config('guessjob.rangeStart'),
                'end' => $request->range['end'] ?? config('guessjob.rangeEnd'),
            ];

        $args['chainLength'] = $request->links ?? config('guessjob.chainLength');
        $args['backoff'] = $request->backoff ?? config('guessjob.backoff');

        for ($i = 1; $i <= $args['chainLength']; $i++) {
            $chain[] = new GuessJob($args);
        }

        event(new StartBatchEvent($chain));

        \App\Models\Batch::create([
            'id_batch' => session('batchId')
        ]);

        $result = ' Args:';
        array_walk_recursive($args, function ($item, $key) use (&$result) {
            $result .= ' ' . $key . ' = ' . $item;
        });

        return response('Started, ' . $result ?? '', 200);
    }

    public function clear()
    {
        Param::where('id', '>', 0)->delete();
        Batch::where('id', '>', 0)->delete();

        return response('Cleared', 200);
    }

    public function batchInfo()
    {
        $batch = Bus::findBatch(session('batchId'));
        //if(!$batch->cancelled()) {
        \App\Models\Batch::updateOrCreate([
            'id_batch' => $batch->id
        ], [
            'progress' => $batch->progress(),
            'links' => $batch->totalJobs,
            'successed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'finished' => $batch->finished()
        ]);
        //}
        return BatchLogsResource::collection(\App\Models\Batch::all());
    }

    public function batchCancel()
    {
        $batch = Bus::findBatch(session('batchId'));
        $batch->cancel();
        \App\Models\Batch::updateOrCreate([
            'id_batch' => $batch->id
        ], [
            'canceled' => true
        ]);
        return BatchLogsResource::collection(\App\Models\Batch::all());
    }
}
