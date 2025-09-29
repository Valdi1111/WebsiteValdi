import TocItem from "@BooksBundle/components/books/contents/toc/TocItem";
import React from "react";

export default function ContentToc({ close, chapter, navigation, navigateTo }) {
    return <ul className="list-unstyled overflow-auto mb-0" style={{ maxHeight: "500px" }}>
        {navigation.map(i =>
            <TocItem key={i.id} close={close} item={i} level={0} chapter={chapter} navigateTo={navigateTo}/>
        )}
    </ul>;
}
