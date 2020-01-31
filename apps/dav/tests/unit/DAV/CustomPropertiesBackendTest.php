<?php
/**
 * @copyright Copyright (c) 2017, Georg Ehrke <oc.list@georgehrke.com>
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DAV\Tests\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\DAV\DAV\CustomPropertiesBackend;
use OCP\IDBConnection;
use OCP\IUser;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Tree;
use Test\TestCase;

/**
 * @group DB
 */
class CustomPropertiesBackendTest extends TestCase {

	/** @var Tree | \PHPUnit_Framework_MockObject_MockObject */
	private $tree;

	/** @var  IDBConnection */
	private $dbConnection;

	/** @var IUser | \PHPUnit_Framework_MockObject_MockObject */
	private $user;

	/** @var CustomPropertiesBackend | \PHPUnit_Framework_MockObject_MockObject */
	private $backend;

	/** @var (Node | \PHPUnit_Framework_MockObject_MockObject)[] */
	private $nodes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->tree = $this->createMock(Tree::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')
			->with()
			->will($this->returnValue('dummy_user_42'));
		$this->dbConnection = \OC::$server->getDatabaseConnection();

		$this->backend = new CustomPropertiesBackend(
			$this->tree,
			$this->dbConnection,
			$this->user
		);

		$this->tree->method('getNodeForPath')
			->willReturnCallback(function ($path) {
				if (isset($this->nodes[$path])) {
					return $this->nodes[$path];
				} else {
					throw new NotFound();
				}
			});
	}

	/**
	 * @param string $path
	 * @return Node|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function addNode($path) {
		$node = $this->createMock(Node::class);
		$node->method('getPath')
			->willReturn($path);
		$this->nodes[$path] = $node;
		return $node;
	}

	protected function tearDown(): void {
		$query = $this->dbConnection->getQueryBuilder();
		$query->delete('properties');
		$query->execute();

		parent::tearDown();
	}

	protected function insertProps(string $user, string $path, array $props) {
		foreach ($props as $name => $value) {
			$this->insertProp($user, $path, $name, $value);
		}
	}

	protected function insertProp(string $user, string $path, string $name, string $value) {
		$query = $this->dbConnection->getQueryBuilder();
		$query->insert('properties')
			->values([
				'userid' => $query->createNamedParameter($user),
				'propertypath' => $query->createNamedParameter($path),
				'propertyname' => $query->createNamedParameter($name),
				'propertyvalue' => $query->createNamedParameter($value),
			]);
		$query->execute();
	}

	protected function getProps(string $user, string $path) {
		$query = $this->dbConnection->getQueryBuilder();
		$query->select('propertyname', 'propertyvalue')
			->from('properties')
			->where($query->expr()->eq('userid', $query->createNamedParameter($user)))
			->where($query->expr()->eq('propertypath', $query->createNamedParameter($path)));
		return $query->execute()->fetchAll(\PDO::FETCH_KEY_PAIR);
	}

	public function testPropFindNoDbCalls() {
		$db = $this->createMock(IDBConnection::class);
		$backend = new CustomPropertiesBackend(
			$this->tree,
			$db,
			$this->user
		);

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->at(0))
			->method('get404Properties')
			->with()
			->will($this->returnValue([
				'{http://owncloud.org/ns}permissions',
				'{http://owncloud.org/ns}downloadURL',
				'{http://owncloud.org/ns}dDC',
				'{http://owncloud.org/ns}size',
			]));

		$db->expects($this->never())
			->method($this->anything());

		$this->addNode('foo_bar_path_1337_0');
		$backend->propFind('foo_bar_path_1337_0', $propFind);
	}

	public function testPropFindCalendarCall() {
		$propFind = $this->createMock(PropFind::class);
		$propFind->method('get404Properties')
			->with()
			->will($this->returnValue([
				'{DAV:}getcontentlength',
				'{DAV:}getcontenttype',
				'{DAV:}getetag',
				'{abc}def',
			]));

		$propFind->method('getRequestedProperties')
			->with()
			->will($this->returnValue([
				'{DAV:}getcontentlength',
				'{DAV:}getcontenttype',
				'{DAV:}getetag',
				'{DAV:}displayname',
				'{urn:ietf:params:xml:ns:caldav}calendar-description',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
				'{abc}def',
			]));

		$props = [
			'{abc}def' => 'a',
			'{DAV:}displayname' => 'b',
			'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'c',
			'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'd',
		];

		$this->insertProps('dummy_user_42', 'calendars/foo/bar_path_1337_0', $props);

		$setProps = [];
		$propFind->method('set')
			->willReturnCallback(function ($name, $value, $status) use (&$setProps) {
				$setProps[$name] = $value;
			});

		$this->addNode('calendars/foo/bar_path_1337_0');

		$this->backend->propFind('calendars/foo/bar_path_1337_0', $propFind);
		$this->assertEquals($props, $setProps);
	}

	/**
	 * @dataProvider propPatchProvider
	 */
	public function testPropPatch(string $path, array $existing, array $props, array $result) {
		$this->insertProps($this->user->getUID(), $path, $existing);
		$this->addNode($path);
		$propPatch = new PropPatch($props);

		$this->backend->propPatch($path, $propPatch);
		$propPatch->commit();

		$storedProps = $this->getProps($this->user->getUID(), $path);
		$this->assertEquals($result, $storedProps);
	}

	public function propPatchProvider() {
		return [
			['foo_bar_path_1337', [], ['{DAV:}displayname' => 'anything'], ['{DAV:}displayname' => 'anything']],
			['foo_bar_path_1337', ['{DAV:}displayname' => 'foo'], ['{DAV:}displayname' => 'anything'], ['{DAV:}displayname' => 'anything']],
			['foo_bar_path_1337', ['{DAV:}displayname' => 'foo'], ['{DAV:}displayname' => null], []],
		];
	}

	public function testDelete() {
		$this->insertProps('dummy_user_42', 'foo_bar_path_1337', ['foo' => 'bar']);
		$this->backend->delete('foo_bar_path_1337');
		$this->assertEquals([], $this->getProps('dummy_user_42', 'foo_bar_path_1337'));
	}

	public function testMove() {
		$this->insertProps('dummy_user_42', 'foo_bar_path_1337', ['foo' => 'bar']);
		$this->backend->move('foo_bar_path_1337', 'bar_foo_path_7331');
		$this->assertEquals([], $this->getProps('dummy_user_42', 'foo_bar_path_1337'));
		$this->assertEquals(['foo' => 'bar'], $this->getProps('dummy_user_42', 'bar_foo_path_7331'));
	}
}
