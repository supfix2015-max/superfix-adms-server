<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();
        // filter current user
        $users = $users->filter(function ($user) {
            return $user->id != Auth::user()->id;
        });

        return view('users.index', compact('users'));
    }

    public function store(Request $request)
    {
        // Validate the request data
        // $request->validate([
        //     'name' => 'required|string|max:255',
        //     'email' => 'required|string|email|max:255|unique:users',
        //     'password' => 'required|string|min:8|confirmed',
        // ]);
        try {
            $user = User::create(
                [
                    'name' => $request->name,
                    'email' => $request->email,
                    'force_to_change_password' => true,
                    'password' => Hash::make($request->password),
                    'is_admin' => $request->isAdmin ?? false,
                ]
            );

            return redirect()->route('users.index')->with('success', 'User created successfully');
        } catch (\Exception $e) {
            return redirect()->route('users.index')->with('failed', 'Failed to create user');
        }

    }

    public function destroy($id)
    {
        if (Auth::user()->is_admin) {
            $user = User::find($id);
            if ($user) {
                $user->delete();
            }

            return redirect()->route('users.index')->with('success', 'User deleted successfully');
        }

        return redirect()->route('users.index')->with('failed', 'Failed to delete user');

    }

    public function showChangePasswordForm()
    {
        return view('auth.passwords.change');
    }

    public function changePassword(Request $request)
    {

        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:4|confirmed',
        ]);

        try {
            $user = Auth::user();
            $user->password = Hash::make($request->password);
            $user->force_to_change_password = false;
            $user->save();
            $user = auth()->user();
            auth()->logout();

            return redirect()->route('login')->with('success', 'Password changed successfully.');
        } catch (\Exception $e) {
            dd($e);

            return redirect()->route('password.change')->with('failed', 'Failed to change password');
        }
    }
}
