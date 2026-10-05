<?php
/*
** Zabbix
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/include/classes/user/CWebUser.php';

require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/forms.inc.php';

$page['file'] = 'lang_switch.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = [
	'to' =>		[T_ZBX_STR, O_OPT,	P_SYS,			IN("'en', 'th'"),	null],
	'back' =>	[T_ZBX_STR, O_OPT,	null,			null,		null]
];
check_fields($fields);

if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
	redirect('index.php');
}

$target = getRequest('to') === 'en' ? 'en_GB' : 'th_TH';

// Persist the choice on the user record so every page renders in the chosen language.
if (CWebUser::$data['lang'] !== $target && API::User()->update([
	'userid' => CWebUser::$data['userid'],
	'lang' => $target
])) {
	CWebUser::$data['lang'] = $target;
}

$back = getRequest('back', '');
if ($back === '' || !CHtmlUrlValidator::validateSameSite($back)) {
	$back = CMenuHelper::getFirstUrl();
}
redirect($back);
