<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Service;

use OCP\IL10N;
use OCP\ILogger;
use OCP\DB\QueryBuilder\IQueryBuilder;

class TracksService {

    private $l10n;
    private $logger;
    private $qb;

    public function __construct (ILogger $logger, IL10N $l10n) {
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->qb = \OC::$server->getDatabaseConnection()->getQueryBuilder();
    }

    /**
     * @param string $userId
     */
    public function getTracksFromDB($userId) {
        $tracks = [];
        $qb = $this->qb;
        $qb->select('id', 'file_id', 'color', 'metadata', 'etag')
            ->from('maps_tracks', 't')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            array_push($tracks, [
                'id' => intval($row['id']),
                'file_id' => intval($row['file_id']),
                'color' => $row['color'],
                'metadata' => $row['metadata'],
                'etag' => $row['etag'],
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $tracks;
    }

    public function getTrackFromDB($id, $userId=null) {
        $track = null;
        $qb = $this->qb;
        $qb->select('id', 'file_id', 'color', 'metadata', 'etag')
            ->from('maps_tracks', 't')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        if ($userId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        }
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $track = [
                'id' => intval($row['id']),
                'file_id' => intval($row['file_id']),
                'color' => $row['color'],
                'metadata' => $row['metadata'],
                'etag' => $row['etag'],
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $track;
    }

    public function addTrackToDB($userId, $fileId, $file) {
        $metadata = $this->generateTrackMetadata($file);
        $etag = $file->getEtag();
        $qb = $this->qb;
        $qb->insert('maps_tracks')
            ->values([
                'user_id' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
                'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'metadata' => $qb->createNamedParameter($metadata, IQueryBuilder::PARAM_STR),
                'etag' => $qb->createNamedParameter($etag, IQueryBuilder::PARAM_STR)
            ]);
        $req = $qb->execute();
        $trackId = $qb->getLastInsertId();
        $qb = $qb->resetQueryParts();
        return $trackId;
    }

    public function editTrackInDB($id, $color, $metadata, $etag) {
        $qb = $this->qb;
        $qb->update('maps_tracks');
        if ($color !== null) {
            $qb->set('color', $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR));
        }
        if ($metadata !== null) {
            $qb->set('metadata', $qb->createNamedParameter($metadata, IQueryBuilder::PARAM_STR));
        }
        if ($etag !== null) {
            $qb->set('etag', $qb->createNamedParameter($etag, IQueryBuilder::PARAM_STR));
        }
        $qb->where(
            $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
        );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function deleteTrackFromDB($id) {
        $qb = $this->qb;
        $qb->delete('maps_tracks')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function deleteTracksFromDB($ids, $userId) {
        $qb = $this->qb;
        $qb->delete('maps_tracks')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        if (count($ids) > 0) {
            $or = $qb->expr()->orx();
            foreach ($ids as $id) {
                $or->add($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            }
            $qb->andWhere($or);
        }
        else {
            return;
        }
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function generateTrackMetadata($file) {
        $STOPPED_SPEED_THRESHOLD = 0.9;

        $name = $file->getName();
        $gpx_content = $file->getContent();

        $lat = '0';
        $lon = '0';
        $total_distance = 0;
        $total_duration = 0;
        $date_begin = null;
        $date_end = null;
        $pos_elevation = 0;
        $neg_elevation = 0;
        $min_elevation = null;
        $max_elevation = null;
        $max_speed = 0;
        $avg_speed = '???';
        $moving_time = 0;
        $moving_distance = 0;
        $stopped_distance = 0;
        $moving_max_speed = 0;
        $moving_avg_speed = 0;
        $stopped_time = 0;
        $north = null;
        $south = null;
        $east = null;
        $west = null;
        $trackNameList = '[';
        $linkurl = '';
        $linktext = '';

        $isGoingUp = False;
        $lastDeniv = null;
        $upBegin = null;
        $downBegin = null;
        $lastTime = null;

        try{
            $gpx = new \SimpleXMLElement($gpx_content);
        }
        catch (\Throwable $e) {
            $this->logger->error(
                "Exception in ".$name." gpx parsing : ".$e->getMessage(),
                ['app' => 'maps']
            );
            return null;
        }

        if (count($gpx->trk) === 0 and count($gpx->rte) === 0 and count($gpx->wpt) === 0){
            $this->logger->error(
                'Nothing to parse in '.$name.' gpx file',
                ['app' => 'maps']
            );
            return null;
        }

        // METADATA
        if (!empty($gpx->metadata) and !empty($gpx->metadata->link)) {
            $linkurl = $gpx->metadata->link['href'];
            if (!empty($gpx->metadata->link->text)) {
                $linktext = $gpx->metadata->link->text;
            }
        }

        // TRACKS
        foreach ($gpx->trk as $track) {
            $trackname = str_replace("\n", '', $track->name);
            if (empty($trackname)) {
                $trackname = '';
            }
            $trackname = str_replace('"', "'", $trackname);
            $trackNameList .= sprintf('"%s",', $trackname);
            foreach ($track->trkseg as $segment) {
                $lastPoint = null;
                $lastTime = null;
                $pointIndex = 0;
                $lastDeniv = null;
                foreach ($segment->trkpt as $point) {
                    if (empty($point['lat']) or empty($point['lon'])) {
                        continue;
                    }
                    if (empty($point->ele)) {
                        $pointele = null;
                    }
                    else{
                        $pointele = floatval($point->ele);
                    }
                    if (empty($point->time)) {
                        $pointtime = null;
                    }
                    else{
                        $pointtime = new \DateTime($point->time);
                    }
                    if ($lastPoint !== null and (!empty($lastPoint->ele))){
                        $lastPointele = floatval($lastPoint->ele);
                    }
                    else{
                        $lastPointele = null;
                    }
                    if ($lastPoint !== null and (!empty($lastPoint->time))){
                        $lastTime = new \DateTime($lastPoint->time);
                    }
                    else{
                        $lastTime = null;
                    }
                    if ($lastPoint !== null){
                        $distToLast = distance($lastPoint, $point);
                    }
                    else{
                        $distToLast = null;
                    }
                    $pointlat = floatval($point['lat']);
                    $pointlon = floatval($point['lon']);
                    if ($pointIndex === 0){
                        if ($lat === '0' and $lon === '0'){
                            $lat = $pointlat;
                            $lon = $pointlon;
                        }
                        if ($pointtime !== null and ($date_begin === null or $pointtime < $date_begin)){
                            $date_begin = $pointtime;
                        }
                        $downBegin = $pointele;
                        if ($north === null){
                            $north = $pointlat;
                            $south = $pointlat;
                            $east = $pointlon;
                            $west = $pointlon;
                        }
                    }

                    if ($pointlat > $north){
                        $north = $pointlat;
                    }
                    if ($pointlat < $south){
                        $south = $pointlat;
                    }
                    if ($pointlon > $east){
                        $east = $pointlon;
                    }
                    if ($pointlon < $west){
                        $west = $pointlon;
                    }
                    if ($pointele !== null and ($min_elevation === null or $pointele < $min_elevation)){
                        $min_elevation = $pointele;
                    }
                    if ($pointele !== null and ($max_elevation === null or $pointele > $max_elevation)){
                        $max_elevation = $pointele;
                    }
                    if ($lastPoint !== null and $pointtime !== null and $lastTime !== null){
                        $t = abs($lastTime->getTimestamp() - $pointtime->getTimestamp());

                        $speed = 0;
                        if ($t > 0){
                            $speed = $distToLast / $t;
                            $speed = $speed / 1000;
                            $speed = $speed * 3600;
                            if ($speed > $max_speed){
                                $max_speed = $speed;
                            }
                        }

                        if ($speed <= $STOPPED_SPEED_THRESHOLD){
                            $stopped_time += $t;
                            $stopped_distance += $distToLast;
                        }
                        else{
                            $moving_time += $t;
                            $moving_distance += $distToLast;
                        }
                    }
                    if ($lastPoint !== null){
                        $total_distance += $distToLast;
                    }
                    if ($lastPoint !== null and $pointele !== null and (!empty($lastPoint->ele))){
                        $deniv = $pointele - floatval($lastPoint->ele);
                    }
                    if ($lastDeniv !== null and $pointele !== null and $lastPoint !== null and (!empty($lastPoint->ele))){
                        // we start to go up
                        if ($isGoingUp === False and $deniv > 0){
                            $upBegin = floatval($lastPoint->ele);
                            $isGoingUp = True;
                            $neg_elevation += ($downBegin - floatval($lastPoint->ele));
                        }
                        if ($isGoingUp === True and $deniv < 0){
                            // we add the up portion
                            $pos_elevation += (floatval($lastPointele) - $upBegin);
                            $isGoingUp = False;
                            $downBegin = floatval($lastPoint->ele);
                        }
                    }
                    // update vars
                    if ($lastPoint !== null and $pointele !== null and (!empty($lastPoint->ele))){
                        $lastDeniv = $deniv;
                    }

                    $lastPoint = $point;
                    $pointIndex += 1;
                }

                if ($lastTime !== null and ($date_end === null or $lastTime > $date_end)){
                    $date_end = $lastTime;
                }
            }

        }

        # ROUTES
        foreach($gpx->rte as $route){
            $routename = str_replace("\n", '', $route->name);
            if (empty($routename)){
                $routename = '';
            }
            $routename = str_replace('"', "'", $routename);
            $trackNameList .= sprintf('"%s",', $routename);

            $lastPoint = null;
            $lastTime = null;
            $pointIndex = 0;
            $lastDeniv = null;
            foreach($route->rtept as $point){
                if (empty($point['lat']) or empty($point['lon'])) {
                    continue;
                }
                if (empty($point->ele)){
                    $pointele = null;
                }
                else{
                    $pointele = floatval($point->ele);
                }
                if (empty($point->time)){
                    $pointtime = null;
                }
                else{
                    $pointtime = new \DateTime($point->time);
                }
                if ($lastPoint !== null and (!empty($lastPoint->ele))){
                    $lastPointele = floatval($lastPoint->ele);
                }
                else{
                    $lastPointele = null;
                }
                if ($lastPoint !== null and (!empty($lastPoint->time))){
                    $lastTime = new \DateTime($lastPoint->time);
                }
                else{
                    $lastTime = null;
                }
                if ($lastPoint !== null){
                    $distToLast = distance($lastPoint, $point);
                }
                else{
                    $distToLast = null;
                }
                $pointlat = floatval($point['lat']);
                $pointlon = floatval($point['lon']);
                if ($pointIndex === 0){
                    if ($lat === '0' and $lon === '0'){
                        $lat = $pointlat;
                        $lon = $pointlon;
                    }
                    if ($pointtime !== null and ($date_begin === null or $pointtime < $date_begin)){
                        $date_begin = $pointtime;
                    }
                    $downBegin = $pointele;
                    if ($north === null){
                        $north = $pointlat;
                        $south = $pointlat;
                        $east = $pointlon;
                        $west = $pointlon;
                    }
                }

                if ($pointlat > $north){
                    $north = $pointlat;
                }
                if ($pointlat < $south){
                    $south = $pointlat;
                }
                if ($pointlon > $east){
                    $east = $pointlon;
                }
                if ($pointlon < $west){
                    $west = $pointlon;
                }
                if ($pointele !== null and ($min_elevation === null or $pointele < $min_elevation)){
                    $min_elevation = $pointele;
                }
                if ($pointele !== null and ($max_elevation === null or $pointele > $max_elevation)){
                    $max_elevation = $pointele;
                }
                if ($lastPoint !== null and $pointtime !== null and $lastTime !== null){
                    $t = abs($lastTime->getTimestamp() - $pointtime->getTimestamp());

                    $speed = 0;
                    if ($t > 0){
                        $speed = $distToLast / $t;
                        $speed = $speed / 1000;
                        $speed = $speed * 3600;
                        if ($speed > $max_speed){
                            $max_speed = $speed;
                        }
                    }

                    if ($speed <= $STOPPED_SPEED_THRESHOLD){
                        $stopped_time += $t;
                        $stopped_distance += $distToLast;
                    }
                    else{
                        $moving_time += $t;
                        $moving_distance += $distToLast;
                    }
                }
                if ($lastPoint !== null){
                    $total_distance += $distToLast;
                }
                if ($lastPoint !== null and $pointele !== null and (!empty($lastPoint->ele))){
                    $deniv = $pointele - floatval($lastPoint->ele);
                }
                if ($lastDeniv !== null and $pointele !== null and $lastPoint !== null and (!empty($lastPoint->ele))){
                    // we start to go up
                    if ($isGoingUp === False and $deniv > 0){
                        $upBegin = floatval($lastPoint->ele);
                        $isGoingUp = True;
                        $neg_elevation += ($downBegin - floatval($lastPoint->ele));
                    }
                    if ($isGoingUp === True and $deniv < 0){
                        // we add the up portion
                        $pos_elevation += (floatval($lastPointele) - $upBegin);
                        $isGoingUp = False;
                        $downBegin = floatval($lastPoint->ele);
                    }
                }
                // update vars
                if ($lastPoint !== null and $pointele !== null and (!empty($lastPoint->ele))){
                    $lastDeniv = $deniv;
                }

                $lastPoint = $point;
                $pointIndex += 1;
            }

            if ($lastTime !== null and ($date_end === null or $lastTime > $date_end)){
                $date_end = $lastTime;
            }
        }

        # TOTAL STATS : duration, avg speed, avg_moving_speed
        if ($date_end !== null and $date_begin !== null){
            $totsec = abs($date_end->getTimestamp() - $date_begin->getTimestamp());
            $total_duration = $totsec;
            if ($totsec === 0){
                $avg_speed = 0;
            }
            else{
                $avg_speed = $total_distance / $totsec;
                $avg_speed = $avg_speed / 1000;
                $avg_speed = $avg_speed * 3600;
                $avg_speed = sprintf('%.2f', $avg_speed);
            }
        }

        // determination of real moving average speed from moving time
        $moving_avg_speed = 0;
        $moving_pace = 0;
        if ($moving_time > 0){
            $moving_avg_speed = $total_distance / $moving_time;
            $moving_avg_speed = $moving_avg_speed / 1000;
            $moving_avg_speed = $moving_avg_speed * 3600;
            $moving_avg_speed = sprintf('%.2f', $moving_avg_speed);
            // pace in minutes/km
            $moving_pace = $moving_time / $total_distance;
            $moving_pace = $moving_pace / 60;
            $moving_pace = $moving_pace * 1000;
            $moving_pace = sprintf('%.2f', $moving_pace);
        }

        # WAYPOINTS
        foreach($gpx->wpt as $waypoint){
            $waypointlat = floatval($waypoint['lat']);
            $waypointlon = floatval($waypoint['lon']);

            if ($lat === '0' and $lon === '0'){
                $lat = $waypointlat;
                $lon = $waypointlon;
            }

            if ($north === null or $waypointlat > $north){
                $north = $waypointlat;
            }
            if ($south === null or $waypointlat < $south){
                $south = $waypointlat;
            }
            if ($east === null or $waypointlon > $east){
                $east = $waypointlon;
            }
            if ($west === null or $waypointlon < $west){
                $west = $waypointlon;
            }
        }

        $trackNameList = trim($trackNameList, ',').']';
        if ($north === null){
            $north = 0;
        }
        if ($south === null){
            $south = 0;
        }
        if ($east === null){
            $east = 0;
        }
        if ($west === null){
            $west = 0;
        }

        $result = sprintf('{"lat":%s, "lng":%s, "name": "%s", "distance": %.3f, "duration": %d, "begin": %d, "end": %d, "posel": %.2f, "negel": %.2f, "minel": %.2f, "maxel": %.2f, "maxspd": %.2f, "avgspd": %.2f, "movtime": %d, "stptime": %d, "movavgspd": %s, "n": %.8f, "s": %.8f, "e": %.8f, "w": %.8f, "trnl": %s, "lnkurl": "%s", "lnktxt": "%s", "movpace": %.2f}',
            $lat,
            $lon,
            str_replace('"', "'", $name),
            $total_distance,
            $total_duration,
            ($date_begin !== null) ? $date_begin->getTimestamp() : -1,
            ($date_end !== null) ? $date_end->getTimestamp() : -1,
            $pos_elevation,
            $neg_elevation,
            ($min_elevation !== null) ? $min_elevation : -1000,
            ($max_elevation !== null) ? $max_elevation : -1000,
            $max_speed,
            $avg_speed,
            $moving_time,
            $stopped_time,
            $moving_avg_speed,
            $north,
            $south,
            $east,
            $west,
            (strlen($trackNameList) < 200) ? $trackNameList : '',
            str_replace('"', "'", $linkurl),
            str_replace('"', "'", $linktext),
            $moving_pace
        );
        return $result;
    }

}

/*
 * return distance between these two gpx points in meters
 */
function distance($p1, $p2){

    $lat1 = (float)$p1['lat'];
    $long1 = (float)$p1['lon'];
    $lat2 = (float)$p2['lat'];
    $long2 = (float)$p2['lon'];

    if ($lat1 === $lat2 and $long1 === $long2){
        return 0;
    }

    // Convert latitude and longitude to
    // spherical coordinates in radians.
    $degrees_to_radians = pi()/180.0;

    // phi = 90 - latitude
    $phi1 = (90.0 - $lat1)*$degrees_to_radians;
    $phi2 = (90.0 - $lat2)*$degrees_to_radians;

    // theta = longitude
    $theta1 = $long1*$degrees_to_radians;
    $theta2 = $long2*$degrees_to_radians;

    // Compute spherical distance from spherical coordinates.

    // For two locations in spherical coordinates
    // (1, theta, phi) and (1, theta, phi)
    // cosine( arc length ) =
    //    sin phi sin phi' cos(theta-theta') + cos phi cos phi'
    // distance = rho * arc length

    $cos = (sin($phi1)*sin($phi2)*cos($theta1 - $theta2) +
           cos($phi1)*cos($phi2));
    // why some cosinus are > than 1 ?
    if ($cos > 1.0){
        $cos = 1.0;
    }
    $arc = acos($cos);

    // Remember to multiply arc by the radius of the earth
    // in your favorite set of units to get length.
    return $arc*6371000;
}
