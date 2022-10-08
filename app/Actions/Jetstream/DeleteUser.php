<?php

namespace App\Actions\Jetstream;

use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * The team deleter implementation.
     *
     * @var \Laravel\Jetstream\Contracts\DeletesTeams
     */
    protected $deletesTeams;

    /**
     * Create a new action instance.
     *
     * @param  \Laravel\Jetstream\Contracts\DeletesTeams  $deletesTeams
     * @return void
     */
    public function __construct(DeletesTeams $deletesTeams)
    {
        /* DeletesTeams コントラクトをDIでもってくる */
        $this->deletesTeams = $deletesTeams;
    }

    /**
     * Delete the given user.
     *
     * @param  mixed  $user
     * @return void
     */
    public function delete($user)
    {
        /* ユーザー削除時の一連の処理を記述 */
        DB::transaction(function () use ($user) {
            $this->deleteTeams($user); // まずdeleteTeams() でユーザーに関連するチームの削除処理を行う
            $user->deleteProfilePhoto(); // プロフィール画像を削除
            $user->tokens->each->delete(); // 当該ユーザーのためのトークンを削除(複数あるのでeach() でそれぞれに処理をかけてる)
            $user->delete(); // ユーザーを削除
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     *
     * @param  mixed  $user
     * @return void
     */
    protected function deleteTeams($user)
    {
        /* 削除対象のユーザーがリレーションを持つチームに対する処理 */
        $user->teams()->detach(); // ユーザーが所属しているチームのリレーションを除去

        /* 当該ユーザーがオーナーになっているチームを削除(複数あるのでeach を使ってる) */
        $user->ownedTeams->each(function ($team) {
            $this->deletesTeams->delete($team);
        });
    }
}