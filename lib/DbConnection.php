<?
use Safe\DateTimeImmutable;
use function Safe\preg_match;
use function Safe\posix_getpwuid;

class DbConnection{
	private ?\PDO $_link = null;
	public int $QueryCount = 0;
	public int $LastQueryAffectedRowCount = 0;

	public function __construct(?string $defaultDatabase = null, string $host = 'localhost', ?string $user = null, string $password = '', bool $forceUtf8 = true){
		if($user === null){
			// Get the user running the script for local socket login
			$user = posix_getpwuid(posix_geteuid());
			if($user){
				$user = $user['name'];
			}
		}

		$connectionString = 'mysql:';

		if(stripos($host, ':') !== false){
			$port = null;
			preg_match('/([^:]*):([0-9]+)/ius', $host, $matches);
			$host = $matches[1];
			if(sizeof($matches) > 2){
				$port = $matches[2];
			}

			$connectionString .= 'host=' . $host;

			if($port !== null){
				$connectionString .= ';port=' . $port;
			}
		}
		else{
			$connectionString .= 'host=' . $host;
		}

		if($defaultDatabase !== null){
			$connectionString .= ';dbname=' . $defaultDatabase;
		}

		$params = [\PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_PERSISTENT => false];

		if($forceUtf8){
			$params[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'set names utf8mb4 collate utf8mb4_unicode_ci;';
		}

		// We can't use persistent connections (connection pooling) because we would have race condition problems with last_insert_id()
		$this->_link = new \PDO($connectionString, $user, $password, $params);
	}

	/**
	* @param string $sql The SQL query to execute.
	* @param array<mixed> $params An array of parameters to bind to the SQL statement.
	* @param string $class The type of object to return in the return array.
	* @return Array<mixed>
	* @throws Exceptions\DuplicateDatabaseKeyException When a unique key constraint has been violated.
	* @throws Exceptions\DatabaseQueryException When an error occurs during execution of the query.
	*/
	public function Query(string $sql, array $params = [], string $class = 'stdClass'): array{
		if($this->_link === null){
			return [];
		}

		$result = [];

		try{
			$handle = $this->_link->prepare($sql);
		}
		catch(\PDOException $ex){
			throw $this->CreateDetailedException($ex, $sql, $params);
		}

		$name = 0;
		foreach($params as $parameter){
			$name++;

			if($parameter instanceof DateTimeInterface){
				$parameter = $parameter->format('Y-m-d H:i:s');
			}
			elseif(is_bool($parameter)){
				// MySQL strict mode requires 0 or 1 instead of true or false
				// Can't use PDO::PARAM_BOOL, it just doesn't work
				if($parameter){
					$parameter = 1;
				}
				else{
					$parameter = 0;
				}
			}
			elseif($parameter instanceof BackedEnum){
				$parameter = $parameter->value;
			}

			if(is_int($parameter)){
				$handle->bindValue($name, $parameter, PDO::PARAM_INT);
			}
			else{
				$handle->bindValue($name, $parameter);
			}
		}

		$deadlockRetries = 0;
		$done = false;
		while(!$done){
			try{
				$result = $this->ExecuteQuery($handle, $class);
				$done = true;
			}
			catch(\PDOException $ex){
				if(isset($ex->errorInfo[1]) && $ex->errorInfo[1] == 1213 && $deadlockRetries < 3){
					// InnoDB deadlock, this is normal and happens occasionally. All we have to do is retry the query.
					$deadlockRetries++;
					usleep(500000 * $deadlockRetries); // Give the deadlock some time to clear up.  Start at .5 seconds
				}
				elseif(isset($ex->errorInfo[1]) && $ex->errorInfo[1] == 1062){
					// Duplicate key, bubble this up without logging it so the business logic can handle it
					throw new Exceptions\DuplicateDatabaseKeyException(str_replace('SQLSTATE[23000]: Integrity constraint violation: 1062 ', '', $ex->getMessage() . '. Query: ' . $sql . '. Parameters: ' . vds($params)));
				}
				else{
					throw $this->CreateDetailedException($ex, $sql, $params);
				}
			}
		}

		$this->QueryCount++;

		return $result;
	}

	/**
	* @param \PDOException $ex The exception to create details from.
	* @param string $sql The prepared SQL that caused the exception.
	* @param array<mixed> $params The parameters passed to the prepared SQL.
	*/
	private function CreateDetailedException(\PDOException $ex, string $sql, array $params): Exceptions\DatabaseQueryException{
		// Throw a custom exception that includes more information on the query and paramaters
		return new Exceptions\DatabaseQueryException('Error when executing query: ' . $ex->getMessage() . '. Query: ' . $sql . '. Parameters: ' . vds($params));
	}

	/**
	* @return Array<mixed>
	* @throws \PDOException When an error occurs during execution of the query.
	*/
	private function ExecuteQuery(\PDOStatement $handle, string $class = 'stdClass'): array{
		$handle->execute();

		$this->LastQueryAffectedRowCount = $handle->rowCount();

		$result = [];
		do{
			try{
				$columnCount = $handle->columnCount();

				if($columnCount == 0){
					continue;
				}

				$metadata = [];

				for($i = 0; $i < $columnCount; $i++){
					$metadata[$i] = $handle->getColumnMeta($i);
				}

				$rows = $handle->fetchAll(\PDO::FETCH_NUM);

				$useObjectFillMethod = method_exists($class, 'FromRow');

				foreach($rows as $row){
					if($useObjectFillMethod){
						$object = new stdClass();
					}
					else{
						$object = new $class();
					}

					for($i = 0; $i < $handle->columnCount(); $i++){
						if($metadata[$i] === false){
							continue;
						}

						if($row[$i] === null){
							$object->{$metadata[$i]['name']} = null;
						}
						else{
							switch($metadata[$i]['native_type'] ?? null){
								case 'DATETIME':
								case 'TIMESTAMP':
									$object->{$metadata[$i]['name']} = new DateTimeImmutable($row[$i], new DateTimeZone('UTC'));
									break;

								case 'LONG':
								case 'TINY':
								case 'SHORT':
								case 'INT24':
								case 'LONGLONG':
									$object->{$metadata[$i]['name']} = intval($row[$i]);
									break;

								case 'FLOAT':
								case 'DOUBLE':
								case 'NEWDECIMAL':
									$object->{$metadata[$i]['name']} = floatval($row[$i]);
									break;

								case 'STRING':
									// We don't check the type VAR_STRING here because in MariaDB, enums are always of type STRING.
									// Since this check is slow, we don't want to run it unnecessarily.
									if($class == 'stdClass'){
										$object->{$metadata[$i]['name']} = $row[$i];
									}
									else{
										// If the column is a string and we're filling a typed object, check if the object property is a backed enum. If so, generate it using from(). Otherwise, fill it with a string.
										// Note: Using ReflectionProperty in this way is pretty slow. Maybe we'll think of a
										// better way to automatically fill enum types later.
										try{
											$rp = new ReflectionProperty($class, $metadata[$i]['name']);
											/** @var ?ReflectionNamedType $property */
											$property = $rp->getType();
											if($property !== null){
												$type = $property->getName();
												if(is_a($type, 'BackedEnum', true)){
													$object->{$metadata[$i]['name']} = $type::from($row[$i]);
												}
												else{
													$object->{$metadata[$i]['name']} = $row[$i];
												}
											}
											else{
												$object->{$metadata[$i]['name']} = $row[$i];
											}
										}
										catch(\Exception){
											$object->{$metadata[$i]['name']} = $row[$i];
										}
									}
									break;

								default:
									$object->{$metadata[$i]['name']} = $row[$i];
									break;
							}
						}
					}

					if($useObjectFillMethod){
						$result[] = $class::FromRow($object);
					}
					else{
						$result[] = $object;
					}
				}
			}
			catch(\PDOException $ex){
				// HY000 is thrown when there is no result set, e.g. for an update operation.
				// If anything besides that is thrown, then send it up the stack
				if(!isset($ex->errorInfo[0]) || $ex->errorInfo[0] != "HY000"){
					throw $ex;
				}
			}
		}
		while($handle->nextRowset());

		return $result;
	}

	public function GetLastInsertedId(): ?int{
		if($this->_link === null){
			return null;
		}

		$id = $this->_link->lastInsertId();

		if($id === false){
			return null;
		}
		else{
			$id = (int)$id;
		}

		if($id == 0){
			return null;
		}

		return $id;
	}
}
