<?php

namespace App\Services;


use Exception;
use App\Models\Subscriber;
use App\Mail\SubscriberMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\SubscriberRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\SubscriberEmailRequest;


class SubscriberService
{

    protected array $subscriberCateFilter = [
        'email',
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return Subscriber::where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = Date('Y-m-d', strtotime($requests['from_date']));
                    $last_date  = Date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('created_at', '>=', $first_date)->whereDate(
                        'created_at',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->subscriberCateFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Subscriber $subscriber): void
    {
        try {
            $subscriber->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * @param SubscriberRequest $request
     * @return Subscriber
     * @throws Exception
     */
    public function store(SubscriberRequest $request): Subscriber
    {
        try {
            $subscriber        = new Subscriber;
            $subscriber->email = $request->email;
            $subscriber->save();
            return $subscriber;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @param SubscriberRequest $request
     * @return Subscriber
     * @throws Exception
     */
    public function sendEmail(SubscriberEmailRequest $request)
    {
        try {
            $subscribers = Subscriber::pluck('email');

            if ($subscribers->isNotEmpty()) {
                // [GENIE Wave1 T1.3 2026-06-16] queue() instead of send() — a synchronous BCC blast to the
                // whole subscriber table blocked the HTTP request on the SMTP round-trip (DoS-ish + slow UX).
                // Queuing moves the send off the request thread (the app already runs a queue worker for the
                // outbox). Same single BCC mail, just dispatched asynchronously.
                Mail::bcc($subscribers->toArray())
                    ->queue(new SubscriberMail($request->subject, $request->message));
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
