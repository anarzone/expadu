<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Marketing\BlogPosts;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function __construct(private readonly BlogPosts $posts) {}

    public function index(): View
    {
        return view('marketing.blog.index', [
            'posts' => $this->posts->all(),
        ]);
    }

    public function show(string $slug): View
    {
        $post = $this->posts->find($slug);

        abort_if($post === null, 404);

        return view('marketing.blog.show', ['post' => $post]);
    }
}
