<?php
return page()->csrf()->then(function (int $id) {
    Note::findOrFail($id)->delete();
    flash('info', '削除しました');
    return redirect('/');
});
