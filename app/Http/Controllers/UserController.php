<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Cohensive\OEmbed\Facades\OEmbed;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

use Auth;
use DB;
use ZipArchive;
use File;

use App\Models\User;
use App\Models\Button;
use App\Models\Link;
use App\Models\LinkType;
use App\Models\UserData;


//Function tests if string starts with certain string (used to test for illegal strings)
function stringStartsWith($haystack, $needle, $case = true)
{
    if ($case) {
        return strpos($haystack, $needle, 0) === 0;
    }
    return stripos($haystack, $needle, 0) === 0;
}

//Function tests if string ends with certain string (used to test for illegal strings)
function stringEndsWith($haystack, $needle, $case = true)
{
    $expectedPosition = strlen($haystack) - strlen($needle);
    if ($case) {
        return strrpos($haystack, $needle, 0) === $expectedPosition;
    }
    return strripos($haystack, $needle, 0) === $expectedPosition;
}

class UserController extends Controller
{

    //Statistics of the number of clicks and links
    public function index()
    {
        $userId = Auth::user()->id;

        $littlelink_name = Auth::user()->littlelink_name;
        $userinfo = User::find($userId);

        $links = Link::where('user_id', $userId)->select('link')->count();
        $clicks = Link::where('user_id', $userId)->sum('click_number');
        $topLinks = Link::where('user_id', $userId)->orderby('click_number', 'desc')
            ->whereNotNull('link')->where('link', '<>', '')
            ->take(5)->get();

        $pageStats = [
            'visitors' => [
                'all' => visits('App\Models\User', $littlelink_name)->count(),
                'day' => visits('App\Models\User', $littlelink_name)->period('day')->count(),
                'week' => visits('App\Models\User', $littlelink_name)->period('week')->count(),
                'month' => visits('App\Models\User', $littlelink_name)->period('month')->count(),
                'year' => visits('App\Models\User', $littlelink_name)->period('year')->count(),
            ],
            'os' => visits('App\Models\User', $littlelink_name)->operatingSystems(),
            'referers' => visits('App\Models\User', $littlelink_name)->refs(),
            'countries' => visits('App\Models\User', $littlelink_name)->countries(),
        ];



        return view('studio/index', ['greeting' => $userinfo->name, 'toplinks' => $topLinks, 'links' => $links, 'clicks' => $clicks, 'pageStats' => $pageStats]);
    }

    //Show littlelink page. example => http://127.0.0.1:8000/+admin
    public function littlelink(request $request)
    {
        $littlelink_name = $request->littlelink;
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }
     
        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();
        
        $links = DB::table('links')->join('buttons', 'buttons.id', '=', 'links.button_id')->select('links.link', 'links.id', 'links.button_id', 'links.title', 'links.custom_css', 'links.custom_icon', 'buttons.name')->where('user_id', $id)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->get();

        return view('littlelink', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    //Show littlelink page as home page if set in config
    public function littlelinkhome(request $request)
    {
        $littlelink_name = env('HOME_URL');
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }
     
        $userinfo = User::select('id', 'name', 'littlelink_name', 'littlelink_description', 'theme', 'role')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();
        
        $links = DB::table('links')->join('buttons', 'buttons.id', '=', 'links.button_id')->select('links.link', 'links.id', 'links.button_id', 'links.title', 'links.custom_css', 'links.custom_icon', 'buttons.name')->where('user_id', $id)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->get();

        return view('littlelink', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    //Show add/update form
    public function AddUpdateLink($id = 0)
    {

        if ($id !== 0) {
            $linkData = Link::find($id);
        } elseif ($id == 0) {
            $linkData = new Link(['typename' => 'link', 'id'=>'0']);
        } else {
            $linkData = new Link(['typename' => 'link', 'id'=>'0']);
        }
        $data['LinkTypes'] = LinkType::get();
        $data['LinkData'] = $linkData;
        $data['LinkID'] = $id;
        $data['linkTypeID'] = "1";
        $data['title'] = "Predefined Site";

        if (Route::currentRouteName() != 'showButtons') {
            $links = DB::table('links')->where('id', $id)->first();

            $bid = $links->button_id;

            if($bid == 1 or $bid == 2){
                $data['linkTypeID'] = "2";
            } elseif ($bid == 42) {
                $data['linkTypeID'] = "3";
            } elseif ($bid == 43) {
                $data['linkTypeID'] = "4";
            } elseif ($bid == 93) {
                $data['linkTypeID'] = "5";
            } elseif ($bid == 6 or $bid == 7) {
                $data['linkTypeID'] = "6";
            } elseif ($bid == 44) {
                $data['linkTypeID'] = "7";
            } else {
                $data['linkTypeID'] = "1";
            }

            $data['title'] = LinkType::where('id', $data['linkTypeID'])->value('title');
        }

        foreach ($data['LinkTypes']->toArray() as $key => $val) {
            if ($val['typename'] === $linkData['typename']) {
                $data['SelectedLinkType'] = $val;
                break;
            }
        }
        
        return view('studio/edit-link', $data);
    }

    //Save add link
    public function saveLink(request $request)
    {

        $linkType = LinkType::find($request->linktype_id);
        $LinkTitle = ($request->link_text ?? $request->link_title) ?? $request->title;
        $LinkURL = $request->link_url ?? $request->link;


        $OrigLink = Link::find($request->linkid);

        $customParams = [];
        foreach ($request->all() as $key => $param) {
            //echo $key . " = " . $param . "<br />";
            if (str_starts_with($key, "_") ||  in_array($key, ['linktype_id', 'linktype_title', 'link_text', 'link_url']))
                continue;

            $customParams[$key] = $param;
        }

        $userId = Auth::user()->id;
        $button = Button::where('name', $request->button)->first();

        if ($button && empty($LinkTitle))
            $LinkTitle = ucwords($button->name);

        if ($linkType->typename == 'video' && empty($LinkTitle)) {
            $embed = OEmbed::get($LinkURL);
            if ($embed) {
                $LinkTitle = $embed->data()['title'];
            }
        }

        $message = (ucwords($button?->name) ?? ucwords($linkType->typename)). " has been ";

        if ($OrigLink) {
            //EDITING EXISTING

            $isCustomWebsite = $customParams['GetSiteIcon'] ?? null;
            $SpacerHeight = $customParams['height'] ?? null;

                if($linkType->typename == "link" and $isCustomWebsite == "1"){
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'title' => $LinkTitle,
                        'button_id' => "2",
                    ]);
                }elseif($linkType->typename == "link"){
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'title' => $LinkTitle,
                        'button_id' => "1",
                    ]);
                }elseif($linkType->typename == "spacer"){
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'title' => $customParams['height'] ?? null,
                        'button_id' => "43",
                    ]);
                }elseif($linkType->typename == "heading"){
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'title' => $LinkTitle,
                        'button_id' => "42",
                    ]);
                }elseif($linkType->typename == "text"){
                    $OrigLink->update([
                        'button_id' => "93",
                        'title' => $request->text,
                    ]);
                }elseif($linkType->typename == "email"){
                    $LinkURL = "mailto:".$LinkURL;
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'button_id' => $button?->id,
                        'title' => $LinkTitle,
                    ]);
                }elseif($linkType->typename == "telephone"){
                    $LinkURL = "tel:".$LinkURL;
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'button_id' => $button?->id,
                        'title' => $LinkTitle,
                    ]);
                }else{
                    $OrigLink->update([
                        'link' => $LinkURL,
                        'title' => $LinkTitle,
                        'button_id' => $button?->id,
                    ]);
                }
                
            $message .="updated";

        } else {
            // ADDING NEW

            $isCustomWebsite = $customParams['GetSiteIcon'] ?? null;
            $SpacerHeight = $customParams['height'] ?? null;
            
            $links = new Link;
            $links->link = $LinkURL;
            $links->user_id = $userId;
            if($linkType->typename == "spacer"){
            $links->title = $SpacerHeight;
            }else{
            $links->title = $LinkTitle;
            }
            if($linkType->typename == "link" and $isCustomWebsite == "1"){
                $links->button_id = "2";
            }elseif($linkType->typename == "link"){
                $links->button_id = "1";
            }elseif($linkType->typename == "spacer"){
                $links->button_id = "43";
            }elseif($linkType->typename == "heading"){
                $links->button_id = "42";
            }elseif($linkType->typename == "text"){
                $links->button_id = "93";
                $links->title = $request->text;
            }elseif($linkType->typename == "email"){
                $links->link = "mailto:".$links->link;
                $links->button_id = $button?->id;
            }elseif($linkType->typename == "telephone"){
                $links->link = "tel:".$links->link;
                $links->button_id = $button?->id;
            }else{
                $links->button_id = $button?->id;
            }

            $links->save();

            $links->order = ($links->id - 1);
            $links->save();
            $message .= "added";
        }

        return Redirect('studio/links')
            ->with('success', $message);

    }

    public function sortLinks(Request $request)
    {
        $linkOrders  = $request->input("linkOrders", []);
        $currentPage = $request->input("currentPage", 1);
        $perPage     = $request->input("perPage", 0);

        if ($perPage == 0) {
            $currentPage = 1;
        }

        $linkOrders = array_unique(array_filter($linkOrders));
        if (!$linkOrders || $currentPage < 1) {
            return response()->json([
                'status' => 'ERROR',
            ]);
        }

        $newOrder = $perPage * ($currentPage - 1);
        $linkNewOrders = [];
        foreach ($linkOrders as $linkId) {
            if ($linkId < 0) {
                continue;
            }

            $linkNewOrders[$linkId] = $newOrder;
            Link::where("id", $linkId)
                ->update([
                    'order' => $newOrder
                ]);
            $newOrder++;
        }

        return response()->json([
            'status' => 'OK',
            'linkOrders' => $linkNewOrders,
        ]);
    }


    //Count the number of clicks and redirect to link
    public function clickNumber(request $request)
    {
        $linkId = $request->id;
        $link = Link::find($linkId);
        $link = $link->link;

        Link::where('id', $linkId)->increment('click_number', 1);

        return redirect()->away($link);
    }

    //Show link, click number, up link in links page
    public function showLinks()
    {
        $userId = Auth::user()->id;
        $data['pagePage'] = 10;
        
        $data['links'] = Link::select('id', 'link', 'title', 'order', 'click_number', 'up_link', 'links.button_id')->where('user_id', $userId)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->paginate(99999);
        return view('studio/links', $data);
    }

    //Delete link
    public function deleteLink(request $request)
    {
        $linkId = $request->id;

        Link::where('id', $linkId)->delete();

        $directory = base_path("studio/favicon/icons");
        $files = scandir($directory);
        foreach($files as $file) {
        if (strpos($file, $linkId.".") !== false) {
        $pathinfo = pathinfo($file, PATHINFO_EXTENSION);}}
        if (isset($pathinfo)) {
        try{File::delete(base_path("studio/favicon/icons")."/".$linkId.".".$pathinfo);} catch (exception $e) {}
        }

        return redirect('/studio/links');
    }

    //Delete icon
    public function clearIcon(request $request)
    {
        $linkId = $request->id;

        $directory = base_path("studio/favicon/icons");
        $files = scandir($directory);
        foreach($files as $file) {
        if (strpos($file, $linkId.".") !== false) {
        $pathinfo = pathinfo($file, PATHINFO_EXTENSION);}}
        if (isset($pathinfo)) {
        try{File::delete(base_path("studio/favicon/icons")."/".$linkId.".".$pathinfo);} catch (exception $e) {}
        }

        return redirect('/studio/links');
    }

    //Raise link on the littlelink page
    public function upLink(request $request)
    {
        $linkId = $request->id;
        $upLink = $request->up;

        if ($upLink == 'yes') {
            $up = 'no';
        } elseif ($upLink == 'no') {
            $up = 'yes';
        }

        Link::where('id', $linkId)->update(['up_link' => $up]);

        return back();
    }

    //Show link to edit
    public function showLink(request $request)
    {
        $linkId = $request->id;

        $link = Link::where('id', $linkId)->value('link');
        $title = Link::where('id', $linkId)->value('title');
        $order = Link::where('id', $linkId)->value('order');
        $custom_css = Link::where('id', $linkId)->value('custom_css');
        $buttonId = Link::where('id', $linkId)->value('button_id');
        $buttonName = Button::where('id', $buttonId)->value('name');

        $buttons = Button::select('id', 'name')->orderBy('name', 'asc')->get();

        return view('studio/edit-link', ['custom_css' => $custom_css, 'buttonId' => $buttonId, 'buttons' => $buttons, 'link' => $link, 'title' => $title, 'order' => $order, 'id' => $linkId, 'buttonName' => $buttonName]);
    }

    //Show custom CSS + custom icon
    public function showCSS(request $request)
    {
        $linkId = $request->id;

        $link = Link::where('id', $linkId)->value('link');
        $title = Link::where('id', $linkId)->value('title');
        $order = Link::where('id', $linkId)->value('order');
        $custom_css = Link::where('id', $linkId)->value('custom_css');
        $custom_icon = Link::where('id', $linkId)->value('custom_icon');
        $buttonId = Link::where('id', $linkId)->value('button_id');

        $buttons = Button::select('id', 'name')->get();

        return view('studio/button-editor', ['custom_icon' => $custom_icon, 'custom_css' => $custom_css, 'buttonId' => $buttonId, 'buttons' => $buttons, 'link' => $link, 'title' => $title, 'order' => $order, 'id' => $linkId]);
    }

    //Save edit link
    public function editLink(request $request)
    {
        $request->validate([
            'link' => 'required',
            'title' => 'required',
            'button' => 'required',
        ]);

        if (stringStartsWith($request->link, 'http://') == 'true' or stringStartsWith($request->link, 'https://') == 'true' or stringStartsWith($request->link, 'mailto:') == 'true')
            $link1 = $request->link;
        else
            $link1 = 'https://' . $request->link;
        if (stringEndsWith($request->link, '/') == 'true')
            $link = rtrim($link1, "/ ");
        else
        $link = $link1;
        $title = $request->title;
        $order = $request->order;
        $button = $request->button;
        $linkId = $request->id;

        $buttonId = Button::select('id')->where('name', $button)->value('id');

        Link::where('id', $linkId)->update(['link' => $link, 'title' => $title, 'order' => $order, 'button_id' => $buttonId]);

        return redirect('/studio/links');
    }

    //Save edit custom CSS + custom icon
    public function editCSS(request $request)
    {
        $linkId = $request->id;
        $custom_icon = $request->custom_icon;
        $custom_css = $request->custom_css;

        if ($request->custom_css == "" and $request->custom_icon = !"") {
            Link::where('id', $linkId)->update(['custom_icon' => $custom_icon]);
        } elseif ($request->custom_icon == "" and $request->custom_css = !"") {
            Link::where('id', $linkId)->update(['custom_css' => $custom_css]);
        } else {
            Link::where('id', $linkId)->update([]);
        }
        return Redirect('#result');
    }

    //Show littlelinke page for edit
    public function showPage(request $request)
    {
        $userId = Auth::user()->id;

        $data['pages'] = User::where('id', $userId)->select('littlelink_name', 'littlelink_description', 'image', 'name')->get();

        return view('/studio/page', $data);
    }

    //Save littlelink page (name, description, logo)
    public function editPage(request $request)
    {
        $request->validate([
            'littlelink_name' => 'sometimes|max:255|string|isunique:users,id,'.Auth::id(),
            'name' => 'sometimes|max:255|string',
        ]);

        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;

        $profilePhoto = $request->file('image');
        $pageName = $request->littlelink_name;
        $pageDescription = strip_tags($request->pageDescription,'<a><p><strong><i><ul><ol><li><blockquote><h2><h3><h4>');
        $pageDescription = preg_replace("/<a([^>]*)>/i", "<a $1 rel=\"noopener noreferrer nofollow\">", $pageDescription);
        $name = $request->name;
        $checkmark = $request->checkmark;

        User::where('id', $userId)->update(['littlelink_name' => $pageName, 'littlelink_description' => $pageDescription, 'name' => $name]);

        if ($request->hasFile('image')) {
            $profilePhoto->move(base_path('/img'), $userId . ".png");
        }

        if($checkmark == "on"){
            UserData::saveData($userId, 'checkmark', true);
        } else {
            UserData::saveData($userId, 'checkmark', false);
        }

        return Redirect('/studio/page');
    }

    //Upload custom theme background image
    public function themeBackground(request $request)
    {

        $userId = Auth::user()->id;
        $littlelink_name = Auth::user()->littlelink_name;

        $customBackground = $request->file('image');

        if (!empty($customBackground)) {
            $directory = base_path('/img/background-img/');
            $files = scandir($directory);
            $pathinfo = "error.error";
            foreach($files as $file) {
            if (strpos($file, $userId.'.') !== false) {
            $pathinfo = $userId. "." . pathinfo($file, PATHINFO_EXTENSION);
            }}
            if(file_exists(base_path('/img/background-img/').$pathinfo)){File::delete(base_path('/img/background-img/').$pathinfo);}

            $customBackground->move(base_path('/img/background-img/'), $userId.".".$request->file('image')->extension());
        }

        return Redirect('/studio/theme');
    }

    //Delete custom background image
    public function removeBackground()
    {

        function findBackground($name){
            $directory = base_path('/img/background-img/');
            $files = scandir($directory);
            $pathinfo = "error.error";
            foreach($files as $file) {
            if (strpos($file, $name.'.') !== false) {
            $pathinfo = $name. "." . pathinfo($file, PATHINFO_EXTENSION);
            }}
            return $pathinfo;
        }

        $user_id = Auth::user()->id;
        $path = findBackground($user_id);
        $path = base_path('/img/background-img/'.$path);
        
        if (File::exists($path)) {
            File::delete($path);
        }

        return back();
    }


    //Show custom theme
    public function showTheme(request $request)
    {
        $userId = Auth::user()->id;

        $data['pages'] = User::where('id', $userId)->select('littlelink_name', 'theme')->get();

        return view('/studio/theme', $data);
    }

    //Save custom theme
    public function editTheme(request $request)
    {
        $request->validate([
            'zip' => 'sometimes|mimes:zip',
        ]);

        $userId = Auth::user()->id;

        $zipfile = $request->file('zip');

        $theme = $request->theme;
        $message = "";

        User::where('id', $userId)->update(['theme' => $theme]);



        if (!empty($zipfile)) {

            $zipfile->move(base_path('/themes'), "temp.zip");

            $zip = new ZipArchive;
            $zip->open(base_path() . '/themes/temp.zip');
            $zip->extractTo(base_path() . '/themes');
            $zip->close();
            unlink(base_path() . '/themes/temp.zip');

            // Removes version numbers from folder.

            $folder = base_path('themes');
            $regex = '/[0-9.-]/';
            $files = scandir($folder);

            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    if (preg_match($regex, $file)) {
                        $new_file = preg_replace($regex, '', $file);
                        File::copyDirectory($folder . '/' . $file, $folder . '/' . $new_file);
                        $dirname = $folder . '/' . $file;
                        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                            system('rmdir ' . escapeshellarg($dirname) . ' /s /q');
                        } else {
                            system("rm -rf " . escapeshellarg($dirname));
                        }
                    }
                }
            }
        }


        return Redirect('/studio/theme')->with("success", $message);
    }

    //Show user (name, email, password)
    public function showProfile(request $request)
    {
        $userId = Auth::user()->id;

        $data['profile'] = User::where('id', $userId)->select('name', 'email', 'role')->get();

        return view('/studio/profile', $data);
    }

    //Save user (name, email, password)
    public function editProfile(request $request)
    {
        $request->validate([
            'name' => 'sometimes|required|unique:users',
            'email' => 'sometimes|required|email|unique:users',
            'password' => 'sometimes|min:8',
        ]);

        $userId = Auth::user()->id;

        $name = $request->name;
        $email = $request->email;
        $password = Hash::make($request->password);

        if ($request->name != '') {
            User::where('id', $userId)->update(['name' => $name]);
        } elseif ($request->email != '') {
            User::where('id', $userId)->update(['email' => $email]);
        } elseif ($request->password != '') {
            User::where('id', $userId)->update(['password' => $password]);
        }
        return back();
    }

    //Show user theme credit page
    public function theme(request $request)
    {
        $littlelink_name = $request->littlelink;
        $id = User::select('id')->where('littlelink_name', $littlelink_name)->value('id');

        if (empty($id)) {
            return abort(404);
        }

        $userinfo = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->first();
        $information = User::select('name', 'littlelink_name', 'littlelink_description', 'theme')->where('id', $id)->get();

        $links = DB::table('links')->join('buttons', 'buttons.id', '=', 'links.button_id')->select('links.link', 'links.id', 'links.button_id', 'links.title', 'links.custom_css', 'links.custom_icon', 'buttons.name')->where('user_id', $id)->orderBy('up_link', 'asc')->orderBy('order', 'asc')->get();

        return view('components/theme', ['userinfo' => $userinfo, 'information' => $information, 'links' => $links, 'littlelink_name' => $littlelink_name]);
    }

    //Delete existing user
    public function deleteUser(request $request)
    {

        // echo $request->id;
        // echo "<br>";
        // echo Auth::id();
        $id = $request->id;

    if($id == Auth::id() and $id != "1") {
        $user = User::find($id);

        Schema::disableForeignKeyConstraints();
        $user->forceDelete();
        Schema::enableForeignKeyConstraints();
    }

        return redirect('/');
    }

    //Delete profile picture
    public function delProfilePicture()
    {
        $user_id = Auth::user()->id;
        $path = base_path('img/' . $user_id . '.png');
        
        if (File::exists($path)) {
            File::delete($path);
        }

        return back();
    }

    //Export user links
    public function exportLinks(request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();
        
        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }

        $userData['links'] = $links->toArray();

        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "links-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ];
        return response()->json($userData, 200, $headers);

        return back();
    }

    //Export all user data
    public function exportAll(Request $request)
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $links = Link::where('user_id', $userId)->get();
    
        if (!$user) {
            // handle the case where the user is null
            return response()->json(['message' => 'User not found'], 404);
        }
    
        $userData = $user->toArray();
        $userData['links'] = $links->toArray();
    
        function findAvatar($name){
            $directory = base_path('/img');
            $files = scandir($directory);
            $pathinfo = "error.error";
            foreach($files as $file) {
            if (strpos($file, $name.'.') !== false) {
            $pathinfo = "/img/" . $name. "." . pathinfo($file, PATHINFO_EXTENSION);
            }}
            return $pathinfo;
          }

        if (file_exists(base_path(findAvatar($userId)))){
            $imagePath = base_path(findAvatar($userId));
            $imageData = base64_encode(file_get_contents($imagePath));
            $userData['image_data'] = $imageData;
    
            $imageExtension = pathinfo($imagePath, PATHINFO_EXTENSION);
            $userData['image_extension'] = $imageExtension;
        }
    
        $domain = $_SERVER['HTTP_HOST'];
        $date = date('Y-m-d_H-i-s');
        $fileName = "user_data-$domain-$date.json";
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ];
        return response()->json($userData, 200, $headers);
    
        return back();
    }    

    public function importData(Request $request)
    {
        try {
            // Get the JSON data from the uploaded file
            if (!$request->hasFile('import') || !$request->file('import')->isValid()) {
                throw new \Exception('File not uploaded or is faulty');
            }
            $file = $request->file('import');
            $jsonString = $file->get();
            $userData = json_decode($jsonString, true);
    
            // Update the authenticated user's profile data if defined in the JSON file
            $user = auth()->user();
            if (isset($userData['name'])) {
                $user->name = $userData['name'];
            }
            if (isset($userData['littlelink_name'])) {
                $user->littlelink_name = $userData['littlelink_name'];
            }
            if (isset($userData['littlelink_description'])) {
                $user->littlelink_description = $userData['littlelink_description'];
            }
            if (isset($userData['image_data'])) {
                // Decode the image data from Base64
                $imageData = base64_decode($userData['image_data']);
                
                // Save the image to the correct path with the correct file name and extension
                $filename = $user->id . '.' . $userData['image_extension'];
                file_put_contents(base_path('img/' . $filename), $imageData);
                
                // Update the user's image field with the correct file name
                $user->image = $filename;
            }
            $user->save();
    
            // Delete all links for the authenticated user
            Link::where('user_id', $user->id)->delete();
    
            // Loop through each link in $userData and create a new link for the user
            foreach ($userData['links'] as $linkData) {
                $newLink = new Link();
    
                // Copy over the link data from $linkData to $newLink
                $newLink->button_id = $linkData['button_id'];
                $newLink->link = $linkData['link'];
                $newLink->title = $linkData['title'];
                $newLink->order = $linkData['order'];
                $newLink->click_number = $linkData['click_number'];
                $newLink->up_link = $linkData['up_link'];
                $newLink->custom_css = $linkData['custom_css'];
                $newLink->custom_icon = $linkData['custom_icon'];
    
                // Set the user ID to the current user's ID
                $newLink->user_id = $user->id;
    
                // Save the new link to the database
                $newLink->save();
            }
    
            return redirect('studio/profile')->with('success', 'Profile updated successfully!');
        } catch (\Exception $e) {
            return redirect('studio/profile')->with('error', 'An error occurred while updating your profile.');
        }
    }
    

    //Edit/save page icons
    public function editIcons(request $request)
    {

        function searchIcon($icon)
        {
            $iconId = DB::table('links')
            ->where('user_id', Auth::id())
            ->where('title', $icon)
            ->where('button_id', 94)
            ->value('id');
        
        if (is_null($iconId)){
            return false;
        } else {
            return $iconId;
        }
        }

        function addIcon($icon, $link){
        $userId = Auth::user()->id;
        $links = new Link;
        $links->link = $link;
        $links->user_id = $userId;
        $links->title = $icon;
        $links->button_id = '94';
        $links->save();
        $links->order = ($links->id - 1);
        $links->save();
    }

        function updateIcon($icon, $link){
        Link::where('id', searchIcon($icon))->update([
            'button_id' => 94,
            'link' => $link,
            'title' => $icon
        ]);
    }

    function saveIcon($icon, $link){
    if(isset($link)){
        if(searchIcon($icon) != NULL){
            updateIcon($icon, $link);
        }else{
            addIcon($icon, $link);}
    }   
}




    saveIcon('mastodon', $request->mastodon);

    saveIcon('instagram', $request->instagram);

    saveIcon('twitter', $request->twitter);

    saveIcon('facebook', $request->facebook);

    saveIcon('github', $request->github);

    saveIcon('linkedin', $request->linkedin);

    saveIcon('tiktok', $request->tiktok);

    saveIcon('discord', $request->discord);

    saveIcon('youtube', $request->youtube);

    saveIcon('snapchat', $request->snapchat);

    saveIcon('reddit', $request->reddit);

    saveIcon('pinterest', $request->pinterest);

    saveIcon('telegram', $request->telegram);

    saveIcon('whatsapp', $request->whatsapp);

    saveIcon('twitch', $request->twitch);




        return Redirect('studio/links#icons');

    }

}