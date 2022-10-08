# Laravel Jetstream のチーム機能について

## インストール

jetstream を入れる時に、`--teams` オプションをつけると有効化される  
以下は livewire 版でインストールする場合のコマンド実例

```
$ sail artisan jetstream:install livewire --teams
```

## チームの種類

-   personal team
    ユーザー登録時に自動的に追加されるチーム。  
    「個人用」のチームとして、そのユーザー自身がオーナーに設定される。  
    `teams` テーブルにおいて、`personal_team` カラムが`true` に設定される。

-   normal team
    仮称。personal team 以外のチーム。ユーザーが能動的に作成したチームすべて。  
    `personal_team` カラムは`false` に設定される。

## オーナーユーザーが削除されたとき、チームはどうなるか

### 実験してみた

-   `user001` でログインして、チーム`test team001`を作成
-   `user001's team` (personal team) に別のユーザー `user002` を追加
-   新しく追加した `test team001` に別のユーザー `user002` を追加
-   `user001` のプロフィール設定画面から、`DELETE ACCOUNT` を実行

#### 結果

`user001's team` と `test team001` が削除された  
どうやら、ユーザーが削除されたときに、そのユーザーがオーナーとして設定されているチームも削除される仕様のよう。

### 実装を確認してみる

ユーザー削除の動作なので、`app/Actions/JetStream/DeleteUser.php` に処理が記述されている

```php
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

```

コードを見たところ、ユーザー削除の処理の中で `deleteTeams()` を実行するようになっており、  
ここで削除するユーザーがオーナーになってるチームの削除を行っている  
データベース側の外部キー制約とかで実現しているのかと思ったら、ふつうにアプリケーション側で実装していた  
データベースのエンジンによっては外部キー制約使えないものもあったりするし、パッケージとしては妥当な仕様なのかも...
