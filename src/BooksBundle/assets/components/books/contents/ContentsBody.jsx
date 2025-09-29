import { ANNOTATIONS, BOOKMARKS, SEARCH, TOC } from "@BooksBundle/components/books/contents/BookContents";
import ContentSearch from "@BooksBundle/components/books/contents/search/ContentSearch";
import ContentToc from "@BooksBundle/components/books/contents/toc/ContentToc";
import React from "react";

export default function ContentsBody({ content, close, search, chapter, navigation, navigateTo }) {
    if (content === TOC) {
        return <ContentToc close={close} chapter={chapter} navigation={navigation} navigateTo={navigateTo}/>;
    }
    if (content === ANNOTATIONS) {
        return <></>;
    }
    if (content === BOOKMARKS) {
        return <></>;
    }
    if (content === SEARCH) {
        return <ContentSearch close={close} navigateTo={navigateTo} search={search}/>;
    }
    return <></>;
}
