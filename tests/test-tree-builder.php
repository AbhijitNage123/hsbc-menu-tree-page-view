<?php

use PHPUnit\Framework\TestCase;

class Test_Tree_Builder extends TestCase {

    public function test_build_tree_structure() {

        $pages = [
            ['ID'=>1,'post_parent'=>0],
            ['ID'=>2,'post_parent'=>1],
            ['ID'=>3,'post_parent'=>1]
        ];

        $tree = HSBC_Tree_Builder::build_tree($pages);

        $this->assertArrayHasKey(1,$tree);
        $this->assertCount(2,$tree[1]['children']);

    }

}