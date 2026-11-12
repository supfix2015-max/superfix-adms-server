<?php

namespace App\Http\Controllers;

use App\Services\ZKTecoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IclockController extends Controller
{
    public function __construct(protected ZKTecoService $zkTecoService) {}

    public function cdata(Request $request): Response
    {
        return $this->respond(fn () => $this->zkTecoService->handleCdata($request));
    }

    public function rtdata(Request $request): Response
    {
        return $this->respond(fn () => $this->zkTecoService->handleRealtimeData($request));
    }

    public function getrequest(Request $request): Response
    {
        return $this->respond(fn () => $this->zkTecoService->handleGetRequest($request));
    }

    public function devicecmd(Request $request): Response
    {
        return $this->respond(fn () => $this->zkTecoService->handleDeviceCommandReply($request));
    }

    public function test(Request $request): Response
    {
        return $this->respond(fn () => $this->zkTecoService->handleTestPing($request));
    }

    private function respond(callable $callback): Response
    {
        try {
            return $this->plainText((string) $callback());
        } catch (AuthorizationException) {
            return $this->plainText("ERROR=Unauthorized\r\n", Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $exception) {
            return $this->plainText("ERROR={$exception->getMessage()}\r\n", Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            Log::error('ZKTeco endpoint failure', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return $this->plainText("ERROR=Server error\r\n", Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function plainText(string $content, int $status = Response::HTTP_OK): Response
    {
        return response($content, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
