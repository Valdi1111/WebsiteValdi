import StandardTable from "@CoreBundle/components/StandardTable";
import { getSeasonFolders } from "@AnimeBundle/api";
import React from 'react';

export default function TableSeasonFolders() {

    return <StandardTable backendFunction={getSeasonFolders} />;

}