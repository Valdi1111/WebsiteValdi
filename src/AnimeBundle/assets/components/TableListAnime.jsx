import StandardTable from "@CoreBundle/components/StandardTable";
import { getListAnime } from "@AnimeBundle/api";
import React from 'react';

export default function TableListAnime() {

    return <StandardTable backendFunction={getListAnime} />;

}