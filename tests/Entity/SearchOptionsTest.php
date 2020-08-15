<?php namespace Tests\Entity;

use BookStack\Entities\SearchOptions;
use Tests\TestCase;

class SearchOptionsTest extends TestCase
{
    private function createRequest(
        $method,
        $content,
        $uri = '/',
        $server = ['CONTENT_TYPE' => 'text/html'],
        $parameters = [],
        $cookies = [],
        $files = []
    ) {
        $request = new \Illuminate\Http\Request;
        return $request->createFromBase(\Symfony\Component\HttpFoundation\Request::create($uri, $method, $parameters, $cookies, $files, $server, $content));
    }

    public function test_from_string_parses_a_search_string_properly()
    {
        $url = '/search?term=' . URLEncode('cat "dog" [tag=good] {is_tree}');
        $request = $this->createRequest('GET', '', $url);
        $options = (new SearchOptions)->fromRequest($request, 'all');

        $this->assertEquals(['cat'], $options->searches);
        $this->assertEquals(['dog'], $options->exacts);
        $this->assertEquals(['tag=good'], $options->tags);
        $this->assertEquals(['is_tree' => ''], $options->filters);
    }

    public function test_to_string_includes_all_items_in_the_correct_format()
    {
        $expected = 'cat "dog" [tag=good] {is_tree}';
        $options = new SearchOptions;
        $options->searches = ['cat'];
        $options->exacts = ['dog'];
        $options->tags = ['tag=good'];
        $options->filters = ['is_tree' => ''];

        $output = $options->toString();
        foreach (explode(' ', $expected) as $term) {
            $this->assertStringContainsString($term, $output);
        }
    }

    public function test_correct_filter_values_are_set_from_string()
    {
        $url = '/search?term=' . URLEncode('{is_tree} {name:dan} {cat:happy}');
        $request = $this->createRequest('GET', '', $url);
        $options = (new SearchOptions)->fromRequest($request, 'all');

        $this->assertEquals([
            'is_tree' => '',
            'name' => 'dan',
            'cat' => 'happy',
        ], $options->filters);
    }
}
